<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agenda\Jobs\ScanSourceJob;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\FirecrawlClient;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgendaSourceScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.agenda.provider' => 'openai',
            'services.openai.key' => 'sk-test',
            'services.openai.extract_model' => 'gpt-5-nano',
            'services.openai.extract_reasoning' => 'minimal',
            'services.firecrawl.key' => 'fc-test',
        ]);
    }

    public function test_sources_scan_batches_only_active_sources_matching_filters(): void
    {
        Bus::fake();
        $concert = Source::factory()->create(['category' => 'musique']);
        $generalist = Source::factory()->create(['category' => null]);
        Source::factory()->create(['category' => 'expo']);
        Source::factory()->inactive()->create(['category' => 'musique']);

        $this->actingAs(User::factory()->create())
            ->postJson('/agenda/scan', ['filters' => ['musique']])
            ->assertAccepted()
            ->assertJson(['mode' => 'sources', 'status' => 'pending', 'sources_total' => 2]);

        Bus::assertBatched(fn (PendingBatch $batch) => collect($batch->jobs)->map(fn (ScanSourceJob $job) => $job->sourceId)->sort()->values()->all()
            === [$concert->id, $generalist->id]);
    }

    public function test_scan_fails_immediately_without_active_source(): void
    {
        Bus::fake();
        Source::factory()->inactive()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/agenda/scan', ['filters' => ['musique']])
            ->assertJson(['status' => 'failed', 'error' => 'Aucune source active pour ces catégories.']);

        Bus::assertNothingBatched();
    }

    public function test_source_page_is_scraped_and_events_extracted(): void
    {
        $this->fakeApis('## Agenda'."\n\n".'![affiche](https://x.test/a.jpg)'."\n\n\n\n".'Concert le 20 octobre');
        $source = Source::factory()->create(['url' => 'https://salle.test/agenda', 'name' => 'Salle Test']);
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources', 'sources_total' => 1]);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        $this->assertDatabaseHas('agenda_events', [
            'title' => 'Concert valide',
            'source_id' => $source->id,
            'place' => 'Salle Test',
            'url' => 'https://salle.test/evenement/1',
        ]);
        $this->assertDatabaseCount('agenda_events', 1);
        $source->refresh();
        $this->assertSame('ok', $source->last_status);
        $this->assertSame(1, $source->last_found);
        $this->assertNotNull($source->content_hash);
        $this->assertSame(['sources_done' => 1, 'found' => 1], $scan->fresh()->only(['sources_done', 'found']));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }

            return $request['model'] === 'gpt-5-nano'
                && $request['reasoning']['effort'] === 'minimal'
                && ! isset($request['tools'])
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['schema']['properties']['events']['items']['properties']['category']['enum'] === ['musique']
                && str_contains($request['input'], 'Concert le 20 octobre')
                && ! str_contains($request['input'], 'a.jpg');
        });
    }

    public function test_unchanged_page_is_not_sent_to_the_ai(): void
    {
        $this->fakeApis('Concert le 20 octobre');
        $source = Source::factory()->create(['content_hash' => app(FirecrawlClient::class)->fingerprint('Concert le 20 octobre')]);
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources', 'sources_total' => 1]);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        Http::assertSentCount(1);
        $this->assertSame('unchanged', $source->fresh()->last_status);
        $this->assertDatabaseCount('agenda_events', 0);
        $this->assertSame(1, $scan->fresh()->sources_done);
    }

    public function test_force_option_reanalyses_unchanged_page(): void
    {
        $this->fakeApis('Concert le 20 octobre');
        $source = Source::factory()->create(['content_hash' => app(FirecrawlClient::class)->fingerprint('Concert le 20 octobre')]);
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources', 'sources_total' => 1]);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique'], true);

        Http::assertSentCount(2);
        $this->assertSame('ok', $source->fresh()->last_status);
    }

    public function test_scrape_failure_is_recorded_on_the_source(): void
    {
        Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => false, 'error' => 'Insufficient credits'], 402)]);
        $source = Source::factory()->create();
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources', 'sources_total' => 1]);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        $source->refresh();
        $this->assertSame('failed', $source->last_status);
        $this->assertStringContainsString('Firecrawl : HTTP 402 Insufficient credits', $source->last_error);
        $this->assertSame(1, $scan->fresh()->sources_done);
    }

    public function test_full_batch_marks_scan_done(): void
    {
        $this->fakeApis('Concert le 20 octobre');
        Source::factory()->count(2)->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/agenda/scan', ['filters' => ['musique']])
            ->assertAccepted();

        $scan = Scan::sole();
        $this->assertSame('done', $scan->status);
        $this->assertSame(2, $scan->sources_done);
        $this->assertSame(2, $scan->found);
    }

    public function test_scan_command_launches_sources_scan(): void
    {
        Bus::fake();
        Source::factory()->create();

        $this->artisan('agenda:scan')->expectsOutputToContain('(1 sources)')->assertSuccessful();

        $this->assertDatabaseHas('agenda_scans', ['mode' => 'sources', 'sources_total' => 1]);
        Bus::assertBatchCount(1);
    }

    /**
     * Firecrawl renvoie $markdown ; OpenAI renvoie un événement valide (lien relatif) et un hors catégorie.
     */
    private function fakeApis(string $markdown): void
    {
        $tomorrow = now('Europe/Madrid')->addDay()->toDateString();
        $json = json_encode(['events' => [
            ['title' => 'Concert valide', 'start' => $tomorrow, 'end' => null, 'time' => '21:00', 'place' => null, 'url' => '/evenement/1', 'category' => 'musique'],
            ['title' => 'Hors catégorie', 'start' => $tomorrow, 'end' => null, 'time' => null, 'place' => null, 'url' => null, 'category' => 'expo'],
        ]]);

        Http::fake([
            'api.firecrawl.dev/*' => Http::response(['success' => true, 'data' => ['markdown' => $markdown, 'metadata' => ['statusCode' => 200]]]),
            'api.openai.com/*' => Http::response(['output' => [
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $json]]],
            ]]),
        ]);
    }
}
