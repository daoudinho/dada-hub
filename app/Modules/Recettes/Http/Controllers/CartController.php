<?php

namespace App\Modules\Recettes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recettes\Models\CartItem;
use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Services\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panier : recettes à cuisiner, chacune pour un nombre de personnes, et liste de courses cumulée.
 */
class CartController extends Controller
{
    public function index(ShoppingList $shoppingList): View
    {
        $items = CartItem::with('recipe.ingredients')->oldest()->get();

        return view('recettes::cart', ['items' => $items, 'lines' => $shoppingList->build($items)]);
    }

    /** Ajoute une recette (ou change son nombre de personnes si elle y est déjà). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $recipe = Recipe::findOrFail($data['recipe_id']);
        CartItem::updateOrCreate(['recipe_id' => $recipe->id], ['servings' => $data['servings'] ?? $recipe->servings]);

        return back()->with('status', '« '.$recipe->name.' » ajoutée au panier.');
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        $data = $request->validate(['servings' => ['required', 'integer', 'min:1', 'max:100']]);

        $recipe->cartItem()->firstOrFail()->update($data);

        return back();
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        $recipe->cartItem()->delete();

        return back()->with('status', '« '.$recipe->name.' » retirée du panier.');
    }

    public function clear(): RedirectResponse
    {
        CartItem::query()->delete();

        return to_route('recettes.cart.index')->with('status', 'Panier vidé.');
    }
}
