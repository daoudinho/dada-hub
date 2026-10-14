<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agenda\Jobs\ScanEventsJob;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgendaModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agenda_requires_authentication(): void
    {
        $this->get('/agenda')->assertRedirect(route('login'));
        $this->getJson('/agenda/events')->assertUnauthorized();
    }

    public function test_agenda_page_renders_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/agenda')
            ->assertOk()
            ->assertSee('Lancer Scan')
            ->assertSee(route('dashboard'), false);
    }

    public function test_events_can_be_listed_created_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $past = Event::factory()->create(['starts_on' => now()->subDays(10)->toDateString()]);

        $id = $this->postJson('/agenda/events', [
            'title' => 'Concert test',
            'starts_on' => now('Europe/Madrid')->addDay()->toDateString(),
            'category' => 'musique',
        ])->assertCreated()->json('id');

        $this->getJson('/agenda/events')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Concert test'])
            ->assertJsonMissing(['id' => $past->id]);

        $this->deleteJson("/agenda/events/{$id}")->assertOk();
        $this->assertDatabaseMissing('agenda_events', ['id' => $id]);
    }

    public function test_web_scan_is_queued(): void
    {
        Bus::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/agenda/scan', ['filters' => ['musique'], 'mode' => 'web'])->assertAccepted();

        Bus::assertDispatched(ScanEventsJob::class);
        $this->assertDatabaseHas('agenda_scans', ['status' => 'pending', 'mode' => 'web']);
    }

    public function test_scan_job_stores_valid_events_from_openai(): void
    {
        config(['services.agenda.provider' => 'openai']);
        Http::fake(['api.openai.com/*' => Http::response(['output' => [
            ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => "Voici :\n".$this->aiEventsJson()]]],
        ]])]);

        $scan = Scan::create(['filters' => ['musique']]);
        ScanEventsJob::dispatchSync($scan->id);

        $this->assertSame('done', $scan->fresh()->status);
        $this->assertSame(1, $scan->fresh()->found);
        $this->assertDatabaseHas('agenda_events', ['title' => 'Concert valide', 'source' => 'scan', 'time' => '21:00']);
        $this->assertDatabaseCount('agenda_events', 1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['tools'][0]['type'] === 'web_search');
    }

    public function test_scan_job_stores_valid_events_from_anthropic(): void
    {
        config(['services.agenda.provider' => 'anthropic']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Voici :\n".$this->aiEventsJson()]]])]);

        $scan = Scan::create(['filters' => ['musique']]);
        ScanEventsJob::dispatchSync($scan->id);

        $this->assertSame('done', $scan->fresh()->status);
        $this->assertSame(1, $scan->fresh()->found);
        $this->assertDatabaseHas('agenda_events', ['title' => 'Concert valide', 'source' => 'scan']);
    }

    public function test_scan_job_marks_scan_failed_on_api_error(): void
    {
        config(['services.agenda.provider' => 'openai']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

        $scan = Scan::create(['filters' => ['musique']]);
        ScanEventsJob::dispatchSync($scan->id);

        $this->assertSame('failed', $scan->fresh()->status);
        $this->assertStringContainsString('API OpenAI : HTTP 401', $scan->fresh()->error);
    }

    /**
     * Réponse type de l'IA : un événement valide, une catégorie non demandée et une date passée.
     */
    private function aiEventsJson(): string
    {
        $tomorrow = now('Europe/Madrid')->addDay()->toDateString();

        return json_encode([
            ['title' => 'Concert valide', 'start' => $tomorrow, 'end' => null, 'time' => '21:00', 'place' => 'Razzmatazz', 'url' => 'https://example.com', 'category' => 'musique'],
            ['title' => 'Mauvaise catégorie', 'start' => $tomorrow, 'category' => 'barca'],
            ['title' => 'Date passée', 'start' => '2000-01-01', 'category' => 'musique'],
        ]);
    }
}
