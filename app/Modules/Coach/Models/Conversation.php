<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Échange avec le coach : premier échange, débrief d'une course, retour sur une séance annulée, ou discussion libre.
 *
 * « pending » : le coach attend l'athlète (débrief en attente) ; « open » : en cours ; « closed » : terminé.
 */
class Conversation extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    public const KINDS = ['onboarding' => 'Premier échange', 'debrief' => 'Débrief', 'cancellation' => 'Séance annulée', 'chat' => 'Discussion'];

    protected $table = 'coach_conversations';

    protected $fillable = ['kind', 'session_id', 'activity_id', 'title', 'status', 'context_hash'];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /** @return BelongsTo<RunningSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(RunningSession::class, 'session_id');
    }

    /** @return BelongsTo<RunningActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(RunningActivity::class, 'activity_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Un débrief ou un retour d'annulation pas encore fait : affiché comme « en attente » sur le tableau de bord. */
    public function isAwaitingFeedback(): bool
    {
        return in_array($this->kind, ['debrief', 'cancellation'], true) && $this->status !== 'closed';
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }
}
