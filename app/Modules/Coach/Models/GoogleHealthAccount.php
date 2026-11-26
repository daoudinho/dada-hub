<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Compte Google relié par l'athlète (OAuth) pour lire ses courses dans Google Health.
 * Les jetons sont chiffrés en base et jamais affichés.
 */
class GoogleHealthAccount extends Model
{
    use BelongsToUser;

    protected $table = 'coach_google_health_accounts';

    protected $fillable = ['health_user_id', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'backfilled_at', 'last_synced_at', 'last_error'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'backfilled_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** Le jeton d'accès expire (1 h) : on le renouvelle une minute avant. */
    public function tokenExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->lte(now()->addMinute());
    }

    /**
     * Google a retiré l'autorisation (révocation, ou 7 jours écoulés tant que l'application Google est « en test ») :
     * l'athlète doit relier son compte de nouveau.
     */
    public function needsReconnect(): bool
    {
        return $this->refresh_token === null;
    }

    /** L'athlète a autorisé la lecture de la fréquence cardiaque (facultative). */
    public function canReadHeartRate(): bool
    {
        return str_contains((string) $this->scopes, 'health_metrics_and_measurements.readonly');
    }
}
