<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message d'une conversation, au format de l'API Claude.
 *
 * L'historique n'est jamais modifié : les blocs (y compris ceux de réflexion et d'appel d'outil)
 * sont renvoyés tels quels à chaque échange. Seuls les messages avec display_text s'affichent.
 */
class Message extends Model
{
    protected $table = 'coach_messages';

    protected $fillable = ['role', 'content', 'display_text', 'model', 'input_tokens', 'output_tokens'];

    protected $casts = [
        'content' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Message tel qu'envoyé à l'API (un message système y est du texte simple).
     *
     * @return array{role: string, content: string|array<int, array<string, mixed>>}
     */
    public function toApi(): array
    {
        if ($this->role === 'system') {
            return ['role' => 'system', 'content' => collect($this->content)->pluck('text')->implode("\n\n")];
        }

        return ['role' => $this->role, 'content' => $this->content];
    }
}
