<?php

namespace App\Modules\Recettes\Models;

use App\Modules\Recettes\Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Recette de cuisine : saisie à la main ou importée par l'IA depuis une page web.
 */
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'servings', 'total_minutes', 'steps', 'source_url', 'enabled',
        'image_hash', 'import_status', 'import_error',
    ];

    protected $attributes = ['steps' => '[]'];

    protected $casts = ['steps' => 'array', 'enabled' => 'boolean', 'servings' => 'integer', 'total_minutes' => 'integer'];

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class)->orderBy('position');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'recipe_tag')->orderBy('name');
    }

    /** @return HasOne<RecipeImage, $this> */
    public function image(): HasOne
    {
        return $this->hasOne(RecipeImage::class);
    }

    /** @return HasOne<CartItem, $this> */
    public function cartItem(): HasOne
    {
        return $this->hasOne(CartItem::class);
    }

    /** @param  Builder<Recipe>  $query */
    public function scopeEnabled(Builder $query, bool $enabled = true): void
    {
        $query->where('enabled', $enabled);
    }

    public function isImporting(): bool
    {
        return $this->import_status === 'pending';
    }

    public function importFailed(): bool
    {
        return $this->import_status === 'failed';
    }

    /** Adresse de l'image, versionnée par son empreinte (mise en cache longue par le navigateur). */
    public function imageUrl(): ?string
    {
        return $this->image_hash === null ? null : route('recettes.images.show', ['recipe' => $this, 'v' => $this->image_hash]);
    }

    /** Temps total lisible : « 45 min », « 1h15 », « 2h ». */
    public function durationLabel(): ?string
    {
        if (! $this->total_minutes) {
            return null;
        }

        $hours = intdiv($this->total_minutes, 60);
        $minutes = $this->total_minutes % 60;

        return match (true) {
            $hours === 0 => $minutes.' min',
            $minutes === 0 => $hours.'h',
            default => $hours.'h'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT),
        };
    }

    /** Texte de recherche (nom et ingrédients, sans accents ni majuscules), lu par le filtre de la liste. */
    public function searchText(): string
    {
        return Str::lower(Str::ascii($this->name.' '.$this->ingredients->pluck('name')->implode(' ')));
    }

    protected static function newFactory(): RecipeFactory
    {
        return RecipeFactory::new();
    }
}
