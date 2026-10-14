<?php

namespace App\Modules\Agenda\Jobs;

use App\Modules\Agenda\Models\AiRequest;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\AiClient;
use App\Modules\Agenda\Services\FirecrawlClient;
use App\Modules\Agenda\Services\Prompts;
use App\Modules\Agenda\Services\SourceExtraction;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Analyse une source : Firecrawl récupère la page, l'IA en extrait les événements.
 * Si la programmation n'a pas changé depuis le dernier passage, l'IA n'est pas appelée.
 * En mode batch, l'extraction est mise de côté pour un lot de l'API Batch (moitié prix).
 */
class ScanSourceJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /**
     * @param  array<int, string>  $categories
     */
    public function __construct(
        public int $scanId,
        public int $sourceId,
        public array $categories,
        public bool $force = false,
        public bool $batchAi = false,
    ) {}

    public function handle(FirecrawlClient $firecrawl, AiClient $ai, Prompts $prompts, SourceExtraction $extraction): void
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

            $page = $firecrawl->scrape($source->url);
            Scan::addUsage($this->scanId, firecrawlCredits: $page->credits);

            if (! $this->force && $source->content_hash === $page->fingerprint) {
                $source->markScanned('unchanged');

                return;
            }

            $prompt = $prompts->forPage($source, $page->markdown, $this->categories);

            if ($this->batchAi) {
                AiRequest::create([
                    'scan_id' => $this->scanId,
                    'source_id' => $source->id,
                    'content_hash' => $page->fingerprint,
                    'categories' => $this->categories,
                    'prompt' => $prompt,
                ]);
                $source->update(['last_status' => 'batch', 'last_error' => null]);

                return;
            }

            $found = $extraction->apply($this->scanId, $source, $page->fingerprint, $this->categories, $ai->extractEvents($prompt, $this->categories));
        } catch (\Throwable $e) {
            Log::warning('Scan agenda : source en échec', ['source' => $this->sourceId, 'error' => $e->getMessage()]);
            $source?->markFailed($e->getMessage());
        } finally {
            Scan::whereKey($this->scanId)->incrementEach(['sources_done' => 1, 'found' => $found]);
        }
    }
}
