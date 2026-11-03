<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Recettes\Models\Ingredient;
use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RecettesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pages_require_authentication(): void
    {
        auth()->logout();

        $this->get('/recettes')->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_module(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Mes Recettes')->assertSee(route('recettes.index'), false);
    }

    public function test_list_shows_enabled_recipes_with_their_tags_and_hides_disabled_ones(): void
    {
        $summer = Tag::factory()->create(['name' => 'Été', 'emoji' => '☀️']);
        Recipe::factory()->hasAttached($summer)->create(['name' => 'Salade tomates burrata', 'total_minutes' => 75]);
        Recipe::factory()->disabled()->create(['name' => 'Vieille recette']);

        $this->get('/recettes')
            ->assertOk()
            ->assertSee('Salade tomates burrata')
            ->assertSee('Été ☀️')
            ->assertSee('1h15')
            ->assertDontSee('Vieille recette')
            ->assertSee('Recettes masquées (1)');

        $this->get('/recettes?masquees=1')->assertSee('Vieille recette')->assertDontSee('Salade tomates burrata');
    }

    public function test_recipe_page_shows_ingredients_and_steps(): void
    {
        $recipe = Recipe::factory()->create(['servings' => 2, 'steps' => ['Coupez les tomates.', 'Ajoutez la burrata.']]);
        Ingredient::factory()->for($recipe)->create(['quantity' => 4, 'unit' => null, 'name' => 'tomates', 'note' => 'bien mûres', 'emoji' => '🍅']);
        Ingredient::factory()->for($recipe)->create(['quantity' => null, 'unit' => null, 'name' => 'sel, poivre', 'position' => 1]);

        $this->get("/recettes/{$recipe->id}")
            ->assertOk()
            ->assertSeeInOrder(['🍅', '4', 'tomates', 'bien mûres', 'sel, poivre'])
            ->assertSeeInOrder(['Coupez les tomates.', 'Ajoutez la burrata.']);
    }

    public function test_recipe_can_be_created_by_hand_ignoring_empty_rows(): void
    {
        $tag = Tag::factory()->create();

        $response = $this->post('/recettes', [
            'name' => 'Ragoût de lentilles',
            'servings' => 4,
            'total_minutes' => 75,
            'enabled' => 1,
            'tag_ids' => [$tag->id],
            'ingredients' => [
                ['emoji' => '🫘', 'quantity' => '0,5', 'unit' => 'kg', 'name' => 'lentilles', 'note' => ''],
                ['emoji' => '', 'quantity' => '', 'unit' => '', 'name' => '', 'note' => ''],
                ['quantity' => '', 'unit' => 'un filet', 'name' => 'huile d\'olive'],
            ],
            'steps' => ['Rincez les lentilles.', '', 'Laissez mijoter 1 h.'],
            'image' => UploadedFile::fake()->image('ragout.jpg', 2000, 1500),
        ]);

        $recipe = Recipe::sole();
        $response->assertRedirect(route('recettes.show', $recipe));
        $this->assertTrue($recipe->enabled);
        $this->assertSame(['Rincez les lentilles.', 'Laissez mijoter 1 h.'], $recipe->steps);
        $this->assertSame([$tag->id], $recipe->tags->modelKeys());
        $this->assertSame(
            [[0.5, 'kg', 'lentilles'], [null, 'un filet', 'huile d\'olive']],
            $recipe->ingredients->map(fn (Ingredient $i) => [$i->quantity, $i->unit, $i->name])->all(),
        );

        $this->assertNotNull($recipe->image_hash);
        $image = $this->get($recipe->imageUrl())->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        $this->assertSame(config('recettes.image.max_width'), getimagesizefromstring($image->getContent())[0]);
    }

    public function test_recipe_creation_is_validated(): void
    {
        $this->post('/recettes', [
            'name' => '',
            'servings' => 0,
            'ingredients' => [['quantity' => 'beaucoup', 'name' => '']],
            'image' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors(['name', 'servings', 'ingredients.0.quantity', 'ingredients.0.name', 'image']);

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_recipe_update_replaces_ingredients_and_can_remove_the_image(): void
    {
        $recipe = Recipe::factory()->create(['image_hash' => str_repeat('a', 40)]);
        $recipe->image()->create(['mime' => 'image/webp', 'binary' => 'old']);
        Ingredient::factory()->for($recipe)->create(['name' => 'ancien']);

        $this->put("/recettes/{$recipe->id}", [
            'name' => 'Nouveau nom',
            'servings' => 3,
            'enabled' => 1,
            'ingredients' => [['quantity' => '200', 'unit' => 'g', 'name' => 'parmesan']],
            'steps' => ['Râpez.'],
            'remove_image' => 1,
        ])->assertRedirect(route('recettes.show', $recipe));

        $recipe->refresh();
        $this->assertSame('Nouveau nom', $recipe->name);
        $this->assertSame(['parmesan'], $recipe->ingredients->pluck('name')->all());
        $this->assertNull($recipe->image_hash);
        $this->assertDatabaseCount('recipe_images', 0);
    }

    public function test_recipe_can_be_hidden_then_shown_again(): void
    {
        $recipe = Recipe::factory()->create();

        $this->patch("/recettes/{$recipe->id}/statut")->assertSessionHas('status', '« '.$recipe->name.' » masquée.');
        $this->assertFalse($recipe->fresh()->enabled);

        $this->patch("/recettes/{$recipe->id}/statut");
        $this->assertTrue($recipe->fresh()->enabled);
    }

    public function test_recipe_can_be_deleted(): void
    {
        $recipe = Recipe::factory()->create();
        Ingredient::factory()->for($recipe)->create();

        $this->delete("/recettes/{$recipe->id}")->assertRedirect(route('recettes.index'));

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredients', 0);
    }

    public function test_tags_can_be_managed(): void
    {
        $this->post('/recettes/etiquettes', ['name' => 'Rapide', 'emoji' => '⚡'])->assertRedirect(route('recettes.tags.index'));
        $tag = Tag::sole();

        $this->post('/recettes/etiquettes', ['name' => 'Rapide'])->assertSessionHasErrorsIn('create', 'name');

        $recipe = Recipe::factory()->hasAttached($tag)->create();
        $this->delete("/recettes/etiquettes/{$tag->id}");

        $this->assertDatabaseCount('recipe_tags', 0);
        $this->assertModelExists($recipe);
    }
}
