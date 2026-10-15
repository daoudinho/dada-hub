<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $table = 'agenda_events';

    protected $fillable = ['title', 'starts_on', 'ends_on', 'time', 'place', 'url', 'category', 'favorite', 'hidden_at', 'source', 'source_id', 'dedupe_key'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'favorite' => 'boolean', 'hidden_at' => 'datetime'];

    /** @return BelongsTo<Source, $this> */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
