<?php

namespace Tests\Feature;

use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Source;
use App\Modules\Agenda\Services\EventImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doublons (titres normalisés) et ménage des événements disparus d'une source.
 */
class AgendaDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private EventImporter $importer;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = app(EventImporter::class);
        $this->date = now('Europe/Madrid')->addDays(3)->toDateString();
    }

    public function test_title_normalization(): void
    {
        $this->assertSame('boheme', EventImporter::normalizeTitle('La Bohème (Puccini)'));
        $this->assertSame('strokes', EventImporter::normalizeTitle('The Strokes – Live in Barcelona 2026'));
        $this->assertSame(EventImporter::dedupeKey('THE STROKES', $this->date), EventImporter::dedupeKey('The Strokes – en concierto', $this->date));
    }

    public function test_reworded_title_on_same_day_updates_existing_event(): void
    {
        $this->import([['title' => 'Band of Horses', 'time' => '21:00']]);
        $this->import([['title' => 'Band of Horses : Things Are Great Tour', 'time' => '20:30']]);

        $this->assertDatabaseCount('agenda_events', 1);
        $this->assertDatabaseHas('agenda_events', ['title' => 'Band of Horses : Things Are Great Tour', 'time' => '20:30']);
    }

    public function test_different_events_on_same_day_are_kept(): void
    {
        $this->import([['title' => 'The Strokes'], ['title' => 'Fontaines D.C.']]);

        $this->assertDatabaseCount('agenda_events', 2);
    }

    public function test_manual_event_is_never_overwritten(): void
    {
        $manual = Event::factory()->create(['title' => 'The Strokes', 'starts_on' => $this->date, 'source' => 'manual', 'place' => 'Mon lieu']);

        $this->import([['title' => 'The Strokes – Live', 'place' => 'Palau Sant Jordi']]);

        $this->assertDatabaseCount('agenda_events', 1);
        $this->assertSame('Mon lieu', $manual->fresh()->place);
    }

    public function test_hidden_event_stays_hidden_after_a_new_scan(): void
    {
        $hidden = Event::factory()->create(['title' => 'The Strokes', 'starts_on' => $this->date, 'source' => 'scan', 'hidden_at' => now()]);

        $ids = $this->import([['title' => 'The Strokes – Live', 'place' => 'Palau Sant Jordi']]);

        $this->assertSame([$hidden->id], $ids);
        $this->assertDatabaseCount('agenda_events', 1);
        $this->assertNotNull($hidden->fresh()->hidden_at);
        $this->assertNotSame('Palau Sant Jordi', $hidden->fresh()->place);
    }

    public function test_first_source_keeps_ownership(): void
    {
        [$razzmatazz, $apolo] = Source::factory()->count(2)->create();

        $this->import([['title' => 'Polyphia']], $razzmatazz);
        $this->import([['title' => 'Polyphia (live)']], $apolo);

        $this->assertSame($razzmatazz->id, Event::sole()->source_id);
    }

    public function test_prune_removes_only_disappeared_future_events_of_the_source(): void
    {
        $source = Source::factory()->create();
        $other = Source::factory()->create();
        $until = now('Europe/Madrid')->addDays(60)->toDateString();

        $kept = $this->import([['title' => 'Toujours là'], ['title' => 'Annulé']], $source);
        $otherEvent = Event::factory()->create(['source' => 'scan', 'source_id' => $other->id, 'starts_on' => $this->date]);
        $manual = Event::factory()->create(['source' => 'manual', 'starts_on' => $this->date]);
        $today = Event::factory()->create(['source' => 'scan', 'source_id' => $source->id, 'starts_on' => now('Europe/Madrid')->toDateString()]);
        $favorite = Event::factory()->create(['source' => 'scan', 'source_id' => $source->id, 'starts_on' => $this->date, 'favorite' => true]);

        $deleted = $this->importer->prune($source, [$kept[0]], $until);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('agenda_events', ['title' => 'Annulé']);
        $this->assertModelExists($otherEvent);
        $this->assertModelExists($manual);
        $this->assertModelExists($today);
        $this->assertModelExists($favorite);
    }

    public function test_dedupe_command_merges_existing_duplicates(): void
    {
        Event::factory()->create(['title' => 'Aida', 'starts_on' => $this->date, 'source' => 'seed', 'dedupe_key' => 'old-1', 'url' => null, 'time' => null, 'place' => null, 'hidden_at' => now()]);
        $complete = Event::factory()->create(['title' => 'Aida (Verdi)', 'starts_on' => $this->date, 'source' => 'scan', 'dedupe_key' => 'old-2', 'url' => 'https://liceu.test/aida', 'time' => '20:00', 'place' => 'Liceu']);

        $this->artisan('agenda:dedupe')->expectsOutputToContain('1 doublon(s) fusionné(s), 1 événement(s) supprimé(s)')->assertSuccessful();

        $this->assertDatabaseCount('agenda_events', 1);
        $this->assertSame(EventImporter::dedupeKey('Aida', $this->date), $complete->fresh()->dedupe_key);
        $this->assertNotNull($complete->fresh()->hidden_at);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int>
     */
    private function import(array $items, ?Source $source = null): array
    {
        $items = array_map(fn (array $item) => $item + ['start' => $this->date, 'category' => 'musique'], $items);

        return $this->importer->import($items, ['musique'], $source);
    }
}
