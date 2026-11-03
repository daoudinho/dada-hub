<?php

namespace App\Modules\Recettes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recettes\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sert l'image d'une recette depuis la base. Une adresse versionnée (?v=empreinte)
 * est mise en cache un an par le navigateur : elle change quand l'image change.
 */
class RecipeImageController extends Controller
{
    public function __invoke(Request $request, Recipe $recipe): Response
    {
        $image = $recipe->image()->firstOrFail();
        $versioned = $recipe->image_hash !== null && $request->query('v') === $recipe->image_hash;

        return response($image->binary, 200, [
            'Content-Type' => $image->mime,
            'Cache-Control' => $versioned ? 'private, max-age=31536000, immutable' : 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
