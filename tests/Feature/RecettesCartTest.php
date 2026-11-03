<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Recettes\Models\CartItem;
use App\Modules\Recettes\Models\Ingredient;
use App\Modules\Recettes\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecettesCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_recipe_is_added_with_its_servings_then_updated_and_removed(): void
    {
        $recipe = Recipe::factory()->create(['servings' => 4]);

        $this->from('/recettes')->post('/recettes/panier', ['recipe_id' => $recipe->id])->assertRedirect('/recettes');
        $this->assertSame(4, CartItem::sole()->servings);

        $this->post('/recettes/panier', ['recipe_id' => $recipe->id, 'servings' => 6]);
        $this->assertSame(6, CartItem::sole()->servings);

        $this->patch("/recettes/panier/{$recipe->id}", ['servings' => 0])->assertSessionHasErrors('servings');
        $this->patch("/recettes/panier/{$recipe->id}", ['servings' => 2]);
        $this->assertSame(2, CartItem::sole()->servings);

        $this->delete("/recettes/panier/{$recipe->id}");
        $this->assertDatabaseCount('recipe_cart_items', 0);
    }

    public function test_shopping_list_scales_and_adds_up_ingredients(): void
    {
        $salad = Recipe::factory()->create(['name' => 'Salade', 'servings' => 2]);
        Ingredient::factory()->for($salad)->create(['quantity' => 4, 'unit' => null, 'name' => 'Tomates']);
        Ingredient::factory()->for($salad)->create(['quantity' => 50, 'unit' => 'cl', 'name' => 'lait']);
        Ingredient::factory()->for($salad)->create(['quantity' => null, 'unit' => null, 'name' => 'sel, poivre']);

        $tart = Recipe::factory()->create(['name' => 'Tarte', 'servings' => 6]);
        Ingredient::factory()->for($tart)->create(['quantity' => 3, 'unit' => null, 'name' => 'tomate']);
        Ingredient::factory()->for($tart)->create(['quantity' => 0.75, 'unit' => 'litres', 'name' => 'Lait']);
        Ingredient::factory()->for($tart)->create(['quantity' => 250, 'unit' => 'g', 'name' => 'farine']);
        Ingredient::factory()->for($tart)->create(['quantity' => null, 'unit' => null, 'name' => 'Sel, poivre']);

        CartItem::create(['recipe_id' => $salad->id, 'servings' => 4]); // ×2
        CartItem::create(['recipe_id' => $tart->id, 'servings' => 6]);  // ×1

        $lines = $this->get('/recettes/panier')->assertOk()->viewData('lines')
            ->mapWithKeys(fn (array $line) => [$line['name'] => [$line['amount'], $line['recipes']]])
            ->all();

        $this->assertSame([
            'farine' => ['250 g', ['Tarte']],
            'lait' => ['1¾ l', ['Salade', 'Tarte']],
            'sel, poivre' => ['', ['Salade', 'Tarte']],
            'Tomates' => ['11', ['Salade', 'Tarte']],
        ], $lines);
    }

    public function test_cart_can_be_emptied(): void
    {
        CartItem::create(['recipe_id' => Recipe::factory()->create()->id, 'servings' => 2]);

        $this->delete('/recettes/panier')->assertRedirect(route('recettes.cart.index'));

        $this->assertDatabaseCount('recipe_cart_items', 0);
    }
}
