<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Recettes\Jobs\ImportRecipeJob;
use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecettesImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.provider' => 'openai',
            'services.openai.key' => 'sk-test',
            'services.openai.extract_model' => 'gpt-5-nano',
            'services.firecrawl.key' => 'fc-test',
        ]);
    }

    public function test_import_form_creates_a_pending_recipe_and_queues_the_job(): void
    {
        Queue::fake();
        $tag = Tag::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post('/recettes/import', ['name' => 'Pâtes à l\'ail', 'source_url' => 'https://cuisine.test/pates', 'tag_ids' => [$tag->id]])
            ->assertRedirect(route('recettes.show', Recipe::sole()));

        $recipe = Recipe::sole();
        $this->assertTrue($recipe->isImporting());
        $this->assertSame([$tag->id], $recipe->tags->modelKeys());
        Queue::assertPushed(ImportRecipeJob::class, fn (ImportRecipeJob $job) => $job->recipeId === $recipe->id);
    }

    public function test_import_requires_a_web_url(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/recettes/import', ['name' => 'Test', 'source_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrorsIn('import', 'source_url');

        Queue::assertNothingPushed();
    }

    public function test_job_fills_the_recipe_from_the_page_keeping_user_name_and_tags(): void
    {
        $summer = Tag::factory()->create(['name' => 'Été']);
        $quick = Tag::factory()->create(['name' => 'Rapide']);
        Tag::factory()->create(['name' => 'Hiver']);
        $recipe = Recipe::factory()->importing('https://cuisine.test/burrata')->hasAttached($quick)->create(['name' => 'Ma burrata']);

        $this->fakeApis([
            'found' => true,
            'description' => 'Fraîche et gourmande.',
            'servings' => 4,
            'total_minutes' => 15,
            'ingredients' => [
                ['quantity' => 4, 'unit' => null, 'name' => 'tomates', 'note' => 'bien mûres', 'emoji' => '🍅'],
                ['quantity' => null, 'unit' => null, 'name' => 'sel, poivre', 'note' => null, 'emoji' => '🧂'],
            ],
            'steps' => ['Coupez les tomates.', 'Ajoutez la burrata.'],
            'tags' => ['Été', 'Inventée'],
        ]);

        ImportRecipeJob::dispatchSync($recipe->id);

        $recipe->refresh();
        $this->assertNull($recipe->import_status);
        $this->assertSame('Ma burrata', $recipe->name);
        $this->assertSame(4, $recipe->servings);
        $this->assertSame(15, $recipe->total_minutes);
        $this->assertSame(['Coupez les tomates.', 'Ajoutez la burrata.'], $recipe->steps);
        $this->assertSame(['tomates', 'sel, poivre'], $recipe->ingredients->pluck('name')->all());
        $this->assertEqualsCanonicalizing([$summer->id, $quick->id], $recipe->tags->modelKeys());
        $this->assertNotNull($recipe->image_hash);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
            && collect($request['text']['format']['schema']['properties']['tags']['items']['enum'])->sort()->values()->all() === collect(['Été', 'Hiver', 'Rapide'])->sort()->values()->all());
    }

    public function test_job_marks_the_recipe_failed_when_the_page_has_no_recipe(): void
    {
        $recipe = Recipe::factory()->importing()->create();
        $this->fakeApis(['found' => false, 'description' => null, 'servings' => null, 'total_minutes' => null, 'ingredients' => [], 'steps' => [], 'tags' => []]);

        ImportRecipeJob::dispatchSync($recipe->id);

        $recipe->refresh();
        $this->assertTrue($recipe->importFailed());
        $this->assertSame('Aucune recette trouvée sur cette page.', $recipe->import_error);
    }

    public function test_failed_import_can_be_retried(): void
    {
        Queue::fake();
        $recipe = Recipe::factory()->importing()->create(['import_status' => 'failed', 'import_error' => 'HTTP 500']);

        $this->actingAs(User::factory()->create())->post("/recettes/{$recipe->id}/import");

        $this->assertTrue($recipe->fresh()->isImporting());
        Queue::assertPushed(ImportRecipeJob::class);
    }

    /**
     * @param  array<string, mixed>  $extracted  réponse JSON de l'IA
     */
    private function fakeApis(array $extracted): void
    {
        Http::fake([
            'api.firecrawl.dev/*' => Http::response(['success' => true, 'data' => [
                'markdown' => "# Salade\n\n4 tomates bien mûres",
                'metadata' => ['statusCode' => 200, 'ogImage' => 'https://cuisine.test/photo.jpg'],
            ]]),
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-5-nano',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($extracted)]]]],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
            ]),
            'cuisine.test/photo.jpg' => Http::response(UploadedFile::fake()->image('photo.jpg', 800, 600)->get(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }
}
