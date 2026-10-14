<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Jobs\ScanEventsJob;
use App\Modules\Agenda\Jobs\ScanSourceJob;
use App\Modules\Agenda\Jobs\SubmitAiBatchJob;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;

/**
 * Crée un scan et met ses jobs en file d'attente (un worker doit tourner : queue:work ou Horizon).
 */
class ScanLauncher
{
    /** Au-delà, un scan resté « en cours » est considéré comme bloqué. */
    public const STALE_MINUTES = 30;

    /** Un lot de l'API Batch peut prendre jusqu'à 24 h. */
    public const BATCH_STALE_HOURS = 26;

    public function __construct(private AiClient $ai) {}

    public function running(): ?Scan
    {
        return Scan::where(fn (Builder $q) => $q
            ->whereIn('status', ['pending', 'running'])->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES)))
            ->orWhere(fn (Builder $q) => $q
                ->where('status', 'waiting')->where('created_at', '>', now()->subHours(self::BATCH_STALE_HOURS)))
            ->latest()->first();
    }

    /**
     * @param  array<int, string>  $filters
     * @param  string  $mode  sources (Firecrawl + IA) ou web (recherche web libre)
     * @param  bool  $force  ignore la fréquence des sources et ré-analyse les pages inchangées
     * @param  bool  $batch  extraction via l'API Batch d'OpenAI (moitié prix, résultat sous 24 h)
     */
    public function start(array $filters, string $mode = 'sources', bool $force = false, bool $batch = false): Scan
    {
        $scan = Scan::create([
            'filters' => array_values(array_unique($filters)),
            'mode' => $mode,
            'force' => $force,
            'batch' => $batch && $mode === 'sources' && $this->ai->supportsBatch(),
        ]);

        if ($mode === 'web') {
            ScanEventsJob::dispatch($scan->id);

            return $scan;
        }

        $categories = Categories::forFilters($scan->filters);
        $sources = Source::scannableFor($categories)->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $scan->update(['status' => 'failed', 'error' => 'Aucune source active pour ces catégories.']);

            return $scan;
        }

        $due = $force ? $sources : $sources->reject(fn (Source $source) => $source->isFresh());
        $scan->update(['sources_total' => $due->count(), 'sources_skipped' => $sources->count() - $due->count()]);

        if ($due->isEmpty()) {
            $scan->update(['status' => 'done']);

            return $scan;
        }

        $scanId = $scan->id;
        $batchAi = $scan->batch;

        Bus::batch($due->map(fn (Source $source) => new ScanSourceJob($scanId, $source->id, $categories, $force, $batchAi))->all())
            ->name('agenda-scan-'.$scanId)
            ->allowFailures()
            ->finally(function () use ($scanId, $batchAi) {
                if ($batchAi) {
                    SubmitAiBatchJob::dispatch($scanId);

                    return;
                }
                Scan::whereKey($scanId)->whereIn('status', ['pending', 'running'])->update(['status' => 'done']);
            })
            ->dispatch();

        return $scan;
    }
}
