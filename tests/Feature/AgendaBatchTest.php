<?php

namespace Tests\Feature;

use App\Modules\Agenda\Models\AiRequest;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Extraction via l'API Batch d'OpenAI (la file « sync » des tests enchaîne envoi, vérification et import).
 */
class AgendaBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.agenda.provider' => 'openai',
            'services.openai.key' => 'sk-test',
            'services.openai.extract_model' => 'gpt-5-nano',
            'services.firecrawl.key' => 'fc-test',
            'agenda.prices.gpt-5-nano' => [0.05, 0.40],
            'agenda.batch_price_factor' => 0.5,
        ]);
    }

    public function test_batch_scan_submits_requests_then_imports_results_at_half_price(): void
    {
        $source = Source::factory()->create(['name' => 'Razzmatazz']);
        $tomorrow = now('Europe/Madrid')->addDay()->toDateString();
        $submitted = null;

        Http::fake(function (Request $request) use ($tomorrow, &$submitted) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://api.firecrawl.dev/') => Http::response(['success' => true, 'data' => ['markdown' => 'Polyphia · 22 novembre', 'metadata' => ['statusCode' => 200]]]),
                $url === 'https://api.openai.com/v1/files' => tap(Http::response(['id' => 'file-in']), function () use ($request, &$submitted) {
                    $submitted = $request->body();
                }),
                $url === 'https://api.openai.com/v1/batches' => Http::response(['id' => 'batch-1', 'status' => 'validating']),
                $url === 'https://api.openai.com/v1/batches/batch-1' => Http::response(['id' => 'batch-1', 'status' => 'completed', 'output_file_id' => 'file-out']),
                $url === 'https://api.openai.com/v1/files/file-out/content' => Http::response(json_encode([
                    'custom_id' => 'agenda-request-1',
                    'response' => ['status_code' => 200, 'body' => [
                        'model' => 'gpt-5-nano',
                        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['events' => [
                            ['title' => 'Polyphia', 'start' => $tomorrow, 'end' => null, 'time' => null, 'place' => null, 'url' => null, 'category' => 'musique'],
                        ]])]]]],
                        'usage' => ['input_tokens' => 10000, 'output_tokens' => 2000],
                    ]],
                    'error' => null,
                ])),
                default => Http::response(['error' => ['message' => 'inattendu : '.$url]], 500),
            };
        });

        $this->artisan('agenda:scan', ['--batch' => true, '--filter' => ['musique']])->assertSuccessful();

        $scan = Scan::sole();
        $this->assertTrue($scan->batch);
        $this->assertSame('done', $scan->status);
        $this->assertSame('batch-1', $scan->ai_batch_id);
        $this->assertSame(1, $scan->found);
        // Moitié prix : (10 000 × 0,05 $ + 2 000 × 0,40 $) / 1 000 000 × 0,5
        $this->assertEqualsWithDelta(0.00065, $scan->cost_usd, 0.000001);

        $this->assertStringContainsString('"custom_id":"agenda-request-1"', $submitted);
        $this->assertStringContainsString('"url":"/v1/responses"', $submitted);
        $this->assertDatabaseHas('agenda_events', ['title' => 'Polyphia', 'source_id' => $source->id]);
        $this->assertSame('done', AiRequest::sole()->status);
        $this->assertSame('ok', $source->fresh()->last_status);
        $this->assertNotNull($source->fresh()->content_hash);
    }

    public function test_failed_batch_marks_scan_failed_without_counting_source_failures(): void
    {
        $source = Source::factory()->create();

        Http::fake(fn (Request $request) => match (true) {
            str_starts_with($request->url(), 'https://api.firecrawl.dev/') => Http::response(['success' => true, 'data' => ['markdown' => 'Concert · 22 novembre', 'metadata' => ['statusCode' => 200]]]),
            $request->url() === 'https://api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
            $request->url() === 'https://api.openai.com/v1/batches' => Http::response(['id' => 'batch-2', 'status' => 'validating']),
            default => Http::response(['id' => 'batch-2', 'status' => 'expired']),
        });

        $this->artisan('agenda:scan', ['--batch' => true])->assertSuccessful();

        $scan = Scan::sole();
        $this->assertSame('failed', $scan->status);
        $this->assertStringContainsString('lot expired', $scan->error);
        $source->refresh();
        $this->assertSame('failed', $source->last_status);
        $this->assertSame(0, $source->failures);
        $this->assertNull($source->content_hash);
    }
}
