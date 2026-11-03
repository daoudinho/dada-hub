<?php

namespace App\Modules\Recettes\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Image d'une recette, stockée en base (le disque de l'hébergeur n'est pas persistant).
 */
class RecipeImage extends Model
{
    protected $primaryKey = 'recipe_id';

    public $incrementing = false;

    protected $fillable = ['recipe_id', 'mime', 'binary'];

    /** Contenu binaire de l'image, stocké en base64 dans la colonne « data ». */
    protected function binary(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => base64_decode((string) ($attributes['data'] ?? '')),
            set: fn (string $value) => ['data' => base64_encode($value)],
        );
    }
}
