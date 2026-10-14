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

    protected $fillable = [
        'name', 'url', 'category', 'active', 'scan_every_days', 'content_hash',
        'last_status', 'last_error', 'failures', 'last_found', 'last_input_tokens', 'last_output_tokens', 'last_scanned_at',
    ];

    protected $casts = ['active' => 'boolean', 'last_scanned_at' => 'datetime'];

    /**
     * Le dernier scan réussi est-il trop récent pour re-scanner (fréquence de la source) ?
     */
    public function isFresh(): bool
    {
        return $this->last_scanned_at !== null
            && in_array($this->last_status, ['ok', 'unchanged'], true)
            && $this->last_scanned_at->gt(now()->subDays(max(1, (int) $this->scan_every_days))->addHour());
    }

    /**
     * Analyse réussie (page inchangée ou extraite) : remet le compteur d'échecs à zéro.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markScanned(string $status, array $attributes = []): void
    {
        $this->update(['last_status' => $status, 'last_error' => null, 'failures' => 0, 'last_scanned_at' => now()] + $attributes);
    }

    /**
     * Échec : désactive la source après agenda.max_failures échecs consécutifs.
     */
    public function markFailed(string $error): void
    {
        $failures = $this->failures + 1;
        $disable = $failures >= (int) config('agenda.max_failures');

        $this->update([
            'last_status' => 'failed',
            'last_error' => mb_substr(($disable ? 'Désactivée automatiquement après '.$failures.' échecs. ' : '').$error, 0, 500),
            'failures' => $failures,
            'active' => $disable ? false : $this->active,
            'last_scanned_at' => now(),
        ]);
    }

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
