<?php

namespace App\Modules\Coach\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Accès Dada Sync d'un athlète : l'app Android envoie ses courses avec une clé personnelle,
 * affichée une seule fois à sa création ; seule son empreinte est gardée.
 */
class SyncAccount extends Model
{
    use BelongsToUser;

    protected $table = 'coach_sync_accounts';

    protected $fillable = ['token_hash', 'token_hint', 'app_version', 'last_seen_at', 'last_synced_at', 'last_sync_runs'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_sync_runs' => 'integer',
    ];

    /**
     * Nouvelle clé pour l'athlète (l'ancienne cesse de fonctionner). Renvoie la clé en clair, à afficher une seule fois.
     */
    public static function issue(User $user): string
    {
        $token = 'dsync_'.Str::random(40);
        $account = self::ownedBy($user)->first() ?? (new self)->user()->associate($user);
        $account->fill(['token_hash' => self::hash($token), 'token_hint' => substr($token, -4)])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', self::hash($token))->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
