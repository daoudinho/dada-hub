<?php

namespace App\Modules\Agenda\Jobs;

use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\AiClient;
use App\Modules\Agenda\Services\EventImporter;
use App\Modules\Agenda\Services\FirecrawlClient;
use App\Modules\Agenda\Services\Prompts;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Analyse une source : Firecrawl récupère la page, l'IA en extrait les événements.
 * Si la page n'a pas changé depuis le dernier passage, l'IA n'est pas appelée.
 */
class ScanSourceJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /**
     * @param  array<int, string>  $categories
     */
    public function __construct(public int $scanId, public int $sourceId, public array $categories, public bool $force = false) {}

    public function handle(FirecrawlClient $firecrawl, AiClient $ai, EventImporter $importer, Prompts $prompts): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Scan::whereKey($this->scanId)->where('status', 'pending')->update(['status' => 'running']);
        $source = Source::find($this->sourceId);
        $found = 0;

        try {
            if ($source === null) {
                return;
            }

            $markdown = $firecrawl->scrape($source->url);
            $hash = sha1($markdown);

            if (! $this->force && $source->content_hash === $hash) {
                $source->update(['last_status' => 'unchanged', 'last_error' => null, 'last_scanned_at' => now()]);

                return;
            }

            $text = $ai->extractEvents($prompts->forPage($source, $markdown, $this->categories), $this->categories);
            $found = $importer->import($importer->parse($text), $this->categories, $source);

            $source->update([
                'content_hash' => $hash,
                'last_status' => 'ok',
                'last_error' => null,
                'last_found' => $found,
                'last_scanned_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Scan agenda : source en échec', ['source' => $this->sourceId, 'error' => $e->getMessage()]);
            $source?->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'last_scanned_at' => now()]);
        } finally {
            Scan::whereKey($this->scanId)->incrementEach(['sources_done' => 1, 'found' => $found]);
        }
    }
}
