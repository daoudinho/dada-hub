<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Programme ou ajustement proposé par le coach : rien ne change au calendrier tant que l'athlète ne l'a pas accepté.
 *
 * payload d'un programme : {feasibility, summary, rationale, sessions: [...]}
 * payload d'un ajustement : {add: [...], update: [{id, ...}], cancel: [id, ...]}
 */
class Proposal extends Model
{
    use BelongsToUser;

    protected $table = 'coach_proposals';

    protected $fillable = ['conversation_id', 'kind', 'reason', 'payload', 'status'];

    protected $casts = ['payload' => 'array'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
