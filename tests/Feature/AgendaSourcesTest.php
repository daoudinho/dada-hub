<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agenda\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_page_requires_authentication(): void
    {
        $this->get('/agenda/sources')->assertRedirect(route('login'));
    }

    public function test_sources_page_lists_sources(): void
    {
        Source::factory()->create(['name' => 'Sala Apolo']);
        Source::factory()->inactive()->create(['name' => 'Sónar']);

        $this->actingAs(User::factory()->create())
            ->get('/agenda/sources')
            ->assertOk()
            ->assertSeeInOrder(['Sala Apolo', 'Sónar'])
            ->assertSee('1 active(s) sur 2');
    }

    public function test_source_can_be_added(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/agenda/sources', ['name' => 'Jamboree', 'url' => 'https://www.jamboreebarcelona.com/en/', 'category' => 'musique'])
            ->assertRedirect(route('agenda.sources.index'));

        $this->assertDatabaseHas('agenda_sources', ['name' => 'Jamboree', 'category' => 'musique', 'active' => true]);
    }

    public function test_source_url_must_be_valid_and_unique(): void
    {
        $existing = Source::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post('/agenda/sources', ['name' => 'Doublon', 'url' => $existing->url])
            ->assertSessionHasErrorsIn('create', 'url');

        $this->post('/agenda/sources', ['name' => 'Pas une URL', 'url' => 'javascript:alert(1)'])
            ->assertSessionHasErrorsIn('create', 'url');

        $this->assertDatabaseCount('agenda_sources', 1);
    }

    public function test_source_can_be_toggled(): void
    {
        $source = Source::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->patch("/agenda/sources/{$source->id}", ['active' => 0])->assertSessionHas('status', '« '.$source->name.' » désactivée.');
        $this->assertFalse($source->fresh()->active);

        $this->patch("/agenda/sources/{$source->id}", ['active' => 1]);
        $this->assertTrue($source->fresh()->active);
    }

    public function test_changing_source_url_resets_its_content_hash(): void
    {
        $source = Source::factory()->create(['content_hash' => sha1('ancienne page')]);

        $this->actingAs(User::factory()->create())
            ->patch("/agenda/sources/{$source->id}", ['name' => $source->name, 'url' => 'https://example.org/agenda', 'category' => ''])
            ->assertRedirect(route('agenda.sources.index'));

        $source->refresh();
        $this->assertSame('https://example.org/agenda', $source->url);
        $this->assertNull($source->category);
        $this->assertNull($source->content_hash);
    }

    public function test_source_can_be_deleted(): void
    {
        $source = Source::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete("/agenda/sources/{$source->id}")
            ->assertRedirect(route('agenda.sources.index'));

        $this->assertModelMissing($source);
    }
}
