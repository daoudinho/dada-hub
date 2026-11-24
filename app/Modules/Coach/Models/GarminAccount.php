<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Jobs\SyncGarminJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Accès Garmin d'un athlète : sa clé d'API personnelle (chiffrée, jamais réaffichée) et l'état de la synchronisation.
 */
class GarminAccount extends Model
{
    use BelongsToUser;

    /** Jours avant l'échéance de la clé à partir desquels on prévient l'athlète. */
    public const EXPIRY_WARNING_DAYS = 14;

    protected $table = 'coach_garmin_accounts';

    protected $fillable = ['api_key', 'key_expires_on', 'access_token', 'token_expires_at', 'backfilled_at', 'last_synced_at', 'last_error', 'history_total', 'last_sync_runs'];

    protected $hidden = ['api_key', 'access_token'];

    protected $casts = [
        'api_key' => 'encrypted',
        'access_token' => 'encrypted',
        'key_expires_on' => 'date',
        'token_expires_at' => 'datetime',
        'backfilled_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'history_total' => 'integer',
        'last_sync_runs' => 'integer',
    ];

    public function keyExpired(): bool
    {
        return $this->key_expires_on !== null && $this->key_expires_on->isBefore(today());
    }

    public function keyExpiresSoon(): bool
    {
        return $this->key_expires_on !== null && ! $this->keyExpired()
            && $this->key_expires_on->lte(today()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    /**
     * Avancement de l'import de l'historique (première synchronisation), null tant qu'elle n'a pas listé les courses.
     * Terminé quand toutes sont là, ou passé le délai prévu (une course peut être écartée à l'import).
     *
     * @return array{imported: int, total: int, done: bool}|null
     */
    public function historyProgress(): ?array
    {
        if (! $this->backfilled_at) {
            return null;
        }

        $total = (int) $this->history_total;
        $imported = min($total, RunningActivity::where('user_id', $this->user_id)->where('source', 'garmin')->count());

        return ['imported' => $imported, 'total' => $total, 'done' => $imported >= $total || now()->isAfter(self::importsDoneAt($this->backfilled_at, $total, 600))];
    }

    /**
     * Synchronisation lancée à $since terminée ? Oui quand elle a listé les courses et que leurs imports, espacés de
     * quelques secondes, ont eu le temps de passer ; ou si elle a échoué.
     */
    public function syncFinishedSince(\DateTimeInterface $since): bool
    {
        if ($this->syncPendingSince($since)) {
            return $this->syncFailedSince($since);
        }

        return now()->gte(self::importsDoneAt($this->last_synced_at, (int) $this->last_sync_runs, 8));
    }

    /** La synchronisation lancée à $since a échoué avant de lister les courses. */
    public function syncFailedSince(\DateTimeInterface $since): bool
    {
        return $this->syncPendingSince($since) && $this->last_error !== null && $this->updated_at->gte($since);
    }

    private function syncPendingSince(\DateTimeInterface $since): bool
    {
        return $this->last_synced_at === null || $this->last_synced_at->lt($since);
    }

    /** Fin estimée des imports de courses, lancés à $start et espacés de quelques secondes chacun. */
    private static function importsDoneAt(\DateTimeInterface $start, int $runs, int $marginSeconds): Carbon
    {
        return Carbon::instance($start)->addSeconds($runs * SyncGarminJob::SPACING_SECONDS + $marginSeconds);
    }

    /** Les 4 derniers caractères, pour reconnaître la clé sans l'afficher. */
    public function keyHint(): string
    {
        return '…'.mb_substr($this->api_key, -4);
    }
}
