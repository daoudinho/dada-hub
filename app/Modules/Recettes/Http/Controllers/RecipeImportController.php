<?php

namespace App\Modules\Recettes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recettes\Jobs\ImportRecipeJob;
use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Services\RecipeWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ajout automatique : la recette est créée avec son nom et son lien, puis remplie par l'IA
 * dans un job (un worker « php artisan queue:work » doit tourner).
 */
class RecipeImportController extends Controller
{
    public function store(Request $request, RecipeWriter $writer): RedirectResponse
    {
        $data = $request->validateWithBag('import', [
            'name' => ['required', 'string', 'max:150'],
            'source_url' => ['required', 'url:http,https', 'max:500'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:recipe_tags,id'],
        ]);

        $recipe = $writer->save(new Recipe, $data + [
            'servings' => config('recettes.default_servings'),
            'import_status' => 'pending',
        ]);

        ImportRecipeJob::dispatch($recipe->id);

        return to_route('recettes.show', $recipe)->with('status', 'Import lancé : la recette se remplit dans quelques instants.');
    }

    /** Relance l'import (après un échec, ou pour remplacer le contenu par celui de la page). */
    public function retry(Recipe $recipe): RedirectResponse
    {
        abort_if($recipe->source_url === null, 404);

        $recipe->update(['import_status' => 'pending', 'import_error' => null]);
        ImportRecipeJob::dispatch($recipe->id);

        return to_route('recettes.show', $recipe)->with('status', 'Import relancé.');
    }
}
