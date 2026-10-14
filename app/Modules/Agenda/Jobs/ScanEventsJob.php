<?php

namespace App\Modules\Agenda\Jobs;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\AiClient;
use App\Modules\Agenda\Services\EventImporter;
use App\Modules\Agenda\Services\Prompts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scan en mode « recherche web libre » : l'IA cherche elle-même sur le web (plus coûteux).
 */
class ScanEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $scanId) {}

    public function handle(AiClient $ai, EventImporter $importer, Prompts $prompts): void
    {
        $scan = Scan::findOrFail($this->scanId);
        $scan->update(['status' => 'running']);

        try {
            $categories = Categories::forFilters($scan->filters);
            $sources = Source::scannableFor($categories)->orderBy('name')->get();

            $text = $ai->searchWeb($prompts->forWebSearch($scan->filters, $categories, $sources));
            $found = $importer->import($importer->parse($text), $categories);
            $scan->update(['status' => 'done', 'found' => $found]);
        } catch (\Throwable $e) {
            Log::error('Scan agenda en échec', ['scan' => $scan->id, 'error' => $e->getMessage()]);
            $scan->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
