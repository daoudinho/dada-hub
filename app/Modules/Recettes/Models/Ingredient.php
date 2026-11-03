<?php

namespace App\Modules\Recettes\Models;

use App\Modules\Recettes\Database\Factories\IngredientFactory;
use App\Modules\Recettes\Services\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'ingrédient d'une recette : « 200 g | farine | (tamisée) ».
 */
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'recipe_ingredients';

    protected $fillable = ['position', 'quantity', 'unit', 'name', 'note', 'emoji'];

    protected $casts = ['quantity' => 'float', 'position' => 'integer'];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** Quantité et unité affichées pour un nombre de personnes donné : « 400 g », « 1½ », « » si non chiffrée. */
    public function amountFor(int $servings, int $baseServings): string
    {
        $quantity = $this->quantity === null ? null : $this->quantity * $servings / max(1, $baseServings);

        return Quantity::label($quantity, $this->unit);
    }

    protected static function newFactory(): IngredientFactory
    {
        return IngredientFactory::new();
    }
}
