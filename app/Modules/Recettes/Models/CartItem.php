<?php

namespace App\Modules\Recettes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recette mise au panier, pour un nombre de personnes donné.
 */
class CartItem extends Model
{
    protected $table = 'recipe_cart_items';

    protected $fillable = ['recipe_id', 'servings'];

    protected $casts = ['servings' => 'integer'];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
