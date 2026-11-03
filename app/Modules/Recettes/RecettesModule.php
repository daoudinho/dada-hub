<?php

namespace App\Modules\Recettes;

use App\Modules\Module;
use App\Modules\Recettes\Models\CartItem;
use Illuminate\Support\Facades\View;

class RecettesModule extends Module
{
    public function slug(): string
    {
        return 'recettes';
    }

    public function name(): string
    {
        return 'Mes Recettes';
    }

    public function description(): string
    {
        return 'Recettes de cuisine, importées depuis un lien par l\'IA ou saisies à la main, et liste de courses.';
    }

    public function icon(): string
    {
        return '🍳';
    }

    public function boot(): void
    {
        parent::boot();

        // Nombre de recettes au panier, affiché dans la barre du haut des pages du module.
        View::composer('recettes::partials.topbar', fn ($view) => $view->with('cartCount', CartItem::count()));
    }
}
