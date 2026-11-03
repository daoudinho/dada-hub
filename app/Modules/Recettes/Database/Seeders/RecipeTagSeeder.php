<?php

namespace App\Modules\Recettes\Database\Seeders;

use App\Modules\Recettes\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * Étiquettes de départ des recettes (relancer sans risque : pas de doublons).
 */
class RecipeTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            'Été' => '☀️',
            'Hiver' => '❄️',
            'Rapide' => '⚡',
            'Végétarien' => '🥕',
            'Dessert' => '🍰',
            'Apéro' => '🥂',
        ];

        foreach ($tags as $name => $emoji) {
            Tag::firstOrCreate(['name' => $name], ['emoji' => $emoji]);
        }
    }
}
