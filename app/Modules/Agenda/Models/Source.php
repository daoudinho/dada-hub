<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Page agenda / programmation d'un lieu, récupérée par Firecrawl lors des scans.
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $table = 'agenda_sources';

    protected $fillable = ['name', 'url', 'category', 'active', 'content_hash', 'last_status', 'last_error', 'last_found', 'last_scanned_at'];

    protected $casts = ['active' => 'boolean', 'last_scanned_at' => 'datetime'];

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Sources actives concernées par ces catégories (les sources généralistes sont toujours incluses).
     *
     * @param  Builder<Source>  $query
     * @param  array<int, string>  $categories
     */
    public function scopeScannableFor(Builder $query, array $categories): void
    {
        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('category')->orWhereIn('category', $categories));
    }

    protected static function newFactory(): SourceFactory
    {
        return SourceFactory::new();
    }
}
