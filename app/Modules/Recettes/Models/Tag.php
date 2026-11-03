<?php

namespace App\Modules\Recettes\Models;

use App\Modules\Recettes\Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Étiquette de recette (« Été », « Rapide »…), servant de filtre dans la liste.
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $table = 'recipe_tags';

    protected $fillable = ['name', 'emoji'];

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_tag');
    }

    public function label(): string
    {
        return trim($this->name.' '.$this->emoji);
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
