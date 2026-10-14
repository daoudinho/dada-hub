<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Jobs\ScanEventsJob;
use App\Modules\Agenda\Jobs\ScanSourceJob;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use Illuminate\Support\Facades\Bus;

/**
 * Crée un scan et met ses jobs en file d'attente (un worker « php artisan queue:work » doit tourner).
 */
class ScanLauncher
{
    /** Au-delà, un scan resté « en cours » est considéré comme bloqué. */
    public const STALE_MINUTES = 30;

    public function running(): ?Scan
    {
        return Scan::whereIn('status', ['pending', 'running'])
            ->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES))
            ->latest()->first();
    }

    /**
     * @param  array<int, string>  $filters
     * @param  string  $mode  sources (Firecrawl + IA) ou web (recherche web libre)
     */
    public function start(array $filters, string $mode = 'sources', bool $force = false): Scan
    {
        $scan = Scan::create([
            'filters' => array_values(array_unique($filters)),
            'mode' => $mode,
            'force' => $force,
        ]);

        if ($mode === 'web') {
            ScanEventsJob::dispatch($scan->id);

            return $scan;
        }

        $categories = Categories::forFilters($scan->filters);
        $jobs = Source::scannableFor($categories)->orderBy('id')->pluck('id')
            ->map(fn (int $sourceId) => new ScanSourceJob($scan->id, $sourceId, $categories, $force))
            ->all();

        if ($jobs === []) {
            $scan->update(['status' => 'failed', 'error' => 'Aucune source active pour ces catégories.']);

            return $scan;
        }

        $scan->update(['sources_total' => count($jobs)]);
        $scanId = $scan->id;

        Bus::batch($jobs)
            ->name('agenda-scan-'.$scanId)
            ->allowFailures()
            ->finally(fn () => Scan::whereKey($scanId)->whereIn('status', ['pending', 'running'])->update(['status' => 'done']))
            ->dispatch();

        return $scan;
    }
}
