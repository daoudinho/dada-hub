<?php

namespace App\Modules\Recettes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recettes\Http\Requests\RecipeRequest;
use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Models\Tag;
use App\Modules\Recettes\Services\RecipeWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Liste, fiche et saisie manuelle des recettes.
 */
class RecipeController extends Controller
{
    public function __construct(private RecipeWriter $writer) {}

    /** Recettes actives, ou masquées avec ?masquees=1. Recherche et filtres par étiquette côté navigateur. */
    public function index(Request $request): View
    {
        $showDisabled = $request->boolean('masquees');

        return view('recettes::index', [
            'recipes' => Recipe::enabled(! $showDisabled)->with(['tags', 'ingredients:id,recipe_id,name', 'cartItem'])->latest()->get(),
            'tags' => Tag::whereHas('recipes', fn ($query) => $query->where('enabled', ! $showDisabled))->orderBy('name')->get(),
            'showDisabled' => $showDisabled,
            'disabledCount' => $showDisabled ? 0 : Recipe::enabled(false)->count(),
        ]);
    }

    public function show(Recipe $recipe): View
    {
        return view('recettes::show', ['recipe' => $recipe->load(['tags', 'ingredients', 'cartItem'])]);
    }

    public function create(Request $request): View
    {
        return view('recettes::create', [
            'recipe' => new Recipe(['servings' => config('recettes.default_servings'), 'enabled' => true]),
            'tags' => Tag::orderBy('name')->get(),
            'mode' => $request->query('mode') === 'manuel' ? 'manual' : 'auto',
        ]);
    }

    public function store(RecipeRequest $request): RedirectResponse
    {
        $recipe = $this->writer->save(new Recipe, $request->safe()->except(['image', 'remove_image']));

        return $this->saveImage($request, $recipe) ?? to_route('recettes.show', $recipe)->with('status', '« '.$recipe->name.' » ajoutée.');
    }

    public function edit(Recipe $recipe): View
    {
        return view('recettes::edit', ['recipe' => $recipe->load(['tags', 'ingredients']), 'tags' => Tag::orderBy('name')->get()]);
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        $this->writer->save($recipe, $request->safe()->except(['image', 'remove_image']));

        if ($request->boolean('remove_image')) {
            $this->writer->removeImage($recipe);
        }

        return $this->saveImage($request, $recipe) ?? to_route('recettes.show', $recipe)->with('status', '« '.$recipe->name.' » modifiée.');
    }

    /** Masque une recette sans l'effacer, ou la réaffiche. */
    public function toggle(Recipe $recipe): RedirectResponse
    {
        $recipe->update(['enabled' => ! $recipe->enabled]);

        return back()->with('status', '« '.$recipe->name.' » '.($recipe->enabled ? 'réaffichée.' : 'masquée.'));
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        $recipe->delete();

        return to_route('recettes.index')->with('status', '« '.$recipe->name.' » supprimée.');
    }

    /** Enregistre l'image envoyée ; en cas d'échec, renvoie vers la modification avec l'erreur. */
    private function saveImage(RecipeRequest $request, Recipe $recipe): ?RedirectResponse
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        try {
            $this->writer->attachImage($recipe, $request->file('image')->get());
        } catch (RuntimeException $e) {
            return to_route('recettes.edit', $recipe)->withErrors(['image' => $e->getMessage()]);
        }

        return null;
    }
}
