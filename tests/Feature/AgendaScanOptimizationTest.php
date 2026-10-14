<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agenda\Jobs\ScanSourceJob;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\FirecrawlClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Mesure de la consommation, empreinte des pages, fréquence des sources, réessais et désactivation automatique.
 */
class AgendaScanOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'services.agenda.provider' => 'openai',
            'services.openai.key' => 'sk-test',
            'services.openai.extract_model' => 'gpt-5-nano',
            'services.firecrawl.key' => 'fc-test',
            'agenda.prices.gpt-5-nano' => [0.05, 0.40],
        ]);
    }

    public function test_scan_records_tokens_cost_and_firecrawl_credits(): void
    {
        $this->fakeApis();
        $source = Source::factory()->create();
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources', 'sources_total' => 1]);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        $scan->refresh();
        $this->assertSame(1, $scan->ai_calls);
        $this->assertSame(10000, $scan->input_tokens);
        $this->assertSame(2000, $scan->output_tokens);
        $this->assertSame(1, $scan->firecrawl_credits);
        // 10 000 × 0,05 $ + 2 000 × 0,40 $ par million de tokens
        $this->assertEqualsWithDelta(0.0013, $scan->cost_usd, 0.000001);
        $this->assertSame(10000, $source->fresh()->last_input_tokens);
    }

    public function test_unknown_model_price_keeps_cost_null(): void
    {
        config(['services.openai.extract_model' => 'modele-inconnu']);
        $this->fakeApis();
        $source = Source::factory()->create();
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources']);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        $this->assertNull($scan->fresh()->cost_usd);
        $this->assertSame(10000, $scan->fresh()->input_tokens);
    }

    public function test_fingerprint_ignores_changes_outside_event_dates(): void
    {
        $firecrawl = app(FirecrawlClient::class);
        $page = "## Programme\n\nThe Strokes\n20 octobre 2026 · 21:00\n\nBand of Horses\n21/10/2026\n\nTeenage Fanclub\n17 oct.";

        $this->assertSame(
            $firecrawl->fingerprint("Mis à jour il y a 5 minutes · 1 204 visiteurs\n\n".$page."\n\n[Billets](https://x.test/?session=abc)"),
            $firecrawl->fingerprint("Mis à jour il y a 2 minutes · 1 377 visiteurs\n\n".$page."\n\n[Billets](https://x.test/?session=xyz)"),
        );
        $this->assertNotSame(
            $firecrawl->fingerprint($page),
            $firecrawl->fingerprint(str_replace('Band of Horses', 'Fontaines D.C.', $page)),
        );
    }

    public function test_recently_scanned_sources_are_skipped_unless_forced(): void
    {
        Bus::fake();
        $recent = Source::factory()->create(['scan_every_days' => 7, 'last_status' => 'unchanged', 'last_scanned_at' => now()->subDays(2)]);
        $due = Source::factory()->create(['scan_every_days' => 7, 'last_status' => 'ok', 'last_scanned_at' => now()->subDays(8)]);
        $failed = Source::factory()->create(['scan_every_days' => 7, 'last_status' => 'failed', 'last_scanned_at' => now()->subDay()]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/agenda/scan', ['filters' => ['musique']])
            ->assertJson(['sources_total' => 2, 'sources_skipped' => 1]);

        Bus::assertBatched(fn (PendingBatch $batch) => collect($batch->jobs)->pluck('sourceId')->sort()->values()->all() === [$due->id, $failed->id]);

        Scan::query()->update(['status' => 'done']);
        $this->postJson('/agenda/scan', ['filters' => ['musique'], 'force' => true])
            ->assertJson(['sources_total' => 3, 'sources_skipped' => 0]);
        $this->assertNotNull($recent);
    }

    public function test_scan_with_only_fresh_sources_ends_immediately(): void
    {
        Bus::fake();
        Source::factory()->create(['last_status' => 'ok', 'last_scanned_at' => now()->subHour()]);

        $this->actingAs(User::factory()->create())
            ->postJson('/agenda/scan', ['filters' => ['musique']])
            ->assertJson(['status' => 'done', 'sources_total' => 0, 'sources_skipped' => 1]);

        Bus::assertNothingBatched();
    }

    public function test_transient_firecrawl_error_is_retried(): void
    {
        Http::fake([
            'api.firecrawl.dev/*' => Http::sequence()
                ->push(['success' => false, 'error' => 'Service unavailable'], 503)
                ->push(['success' => true, 'data' => ['markdown' => 'Concert le 20 octobre', 'metadata' => ['statusCode' => 200]]]),
            'api.openai.com/*' => $this->openAiResponse(),
        ]);
        $source = Source::factory()->create();
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources']);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);

        $this->assertSame('ok', $source->fresh()->last_status);
        Sleep::assertSleptTimes(1);
    }

    public function test_source_is_disabled_after_consecutive_failures(): void
    {
        config(['agenda.max_failures' => 3]);
        Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => false, 'error' => 'Not found'], 404)]);
        $source = Source::factory()->create(['failures' => 1]);
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources']);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);
        $this->assertTrue($source->fresh()->active);
        $this->assertSame(2, $source->fresh()->failures);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);
        $source->refresh();
        $this->assertFalse($source->active);
        $this->assertStringStartsWith('Désactivée automatiquement après 3 échecs.', $source->last_error);
    }

    public function test_success_resets_failures_and_manual_reactivation_too(): void
    {
        $this->fakeApis();
        $source = Source::factory()->create(['failures' => 2]);
        $scan = Scan::create(['filters' => ['musique'], 'mode' => 'sources']);

        ScanSourceJob::dispatchSync($scan->id, $source->id, ['musique']);
        $this->assertSame(0, $source->fresh()->failures);

        $disabled = Source::factory()->inactive()->create(['failures' => 3]);
        $this->actingAs(User::factory()->create())->patch("/agenda/sources/{$disabled->id}", ['active' => 1]);
        $this->assertSame(0, $disabled->fresh()->failures);
    }

    private function fakeApis(): void
    {
        Http::fake([
            'api.firecrawl.dev/*' => Http::response(['success' => true, 'data' => ['markdown' => 'Concert le 20 octobre', 'metadata' => ['statusCode' => 200]]]),
            'api.openai.com/*' => $this->openAiResponse(),
        ]);
    }

    private function openAiResponse(): PromiseInterface
    {
        $tomorrow = now('Europe/Madrid')->addDay()->toDateString();

        return Http::response([
            'model' => config('services.openai.extract_model'),
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['events' => [
                ['title' => 'Concert test', 'start' => $tomorrow, 'end' => null, 'time' => null, 'place' => null, 'url' => null, 'category' => 'musique'],
            ]])]]]],
            'usage' => ['input_tokens' => 10000, 'output_tokens' => 2000],
        ]);
    }
}
