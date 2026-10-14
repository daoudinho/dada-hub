<?php

namespace App\Modules\Agenda\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extraction en attente d'envoi (ou de résultat) dans un lot de l'API Batch d'OpenAI.
 */
class AiRequest extends Model
{
    protected $table = 'agenda_ai_requests';

    protected $fillable = ['scan_id', 'source_id', 'content_hash', 'categories', 'prompt', 'status'];

    protected $casts = ['categories' => 'array'];

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /** Identifiant de la requête dans le fichier JSONL envoyé à OpenAI. */
    public function customId(): string
    {
        return 'agenda-request-'.$this->id;
    }
}
