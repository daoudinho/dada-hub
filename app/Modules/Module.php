<?php

namespace App\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base d'un sous-projet du hub.
 *
 * Chaque module vit dans app/Modules/{Nom}/ et peut contenir :
 *  - routes.php            : routes, montées sous /{slug}, nommées "{slug}.*", protégées par "auth"
 *  - views/                : vues Blade, appelées via view('{slug}::nom')
 *  - Database/Migrations/  : migrations, lancées par "php artisan migrate"
 */
abstract class Module extends ServiceProvider
{
    /** Identifiant utilisé pour l'URL, le nom des routes et le namespace des vues. */
    abstract public function slug(): string;

    /** Nom affiché dans le hub. */
    abstract public function name(): string;

    /** Courte description affichée sur la tuile du hub. */
    public function description(): string
    {
        return '';
    }

    /** Emoji ou court texte affiché sur la tuile. */
    public function icon(): string
    {
        return '📦';
    }

    /**
     * Middlewares appliqués à toutes les routes du module.
     *
     * @return array<int, string>
     */
    public function middleware(): array
    {
        return ['web', 'auth'];
    }

    /** Route d'entrée du module (lien de la tuile). */
    public function entryRoute(): string
    {
        return $this->slug().'.index';
    }

    /**
     * Commandes Artisan du module.
     *
     * @return array<int, class-string>
     */
    public function consoleCommands(): array
    {
        return [];
    }

    /** Tâches planifiées du module (lancées par « php artisan schedule:run »). */
    public function schedule(Schedule $schedule): void {}

    public function path(string $relative = ''): string
    {
        $dir = dirname((new ReflectionClass($this))->getFileName());

        return $relative === '' ? $dir : $dir.DIRECTORY_SEPARATOR.$relative;
    }

    public function boot(): void
    {
        if (is_dir($this->path('views'))) {
            $this->loadViewsFrom($this->path('views'), $this->slug());
        }

        if (is_dir($this->path('Database/Migrations'))) {
            $this->loadMigrationsFrom($this->path('Database/Migrations'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands($this->consoleCommands());
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));
        }

        if (is_file($this->path('routes.php')) && ! $this->app->routesAreCached()) {
            Route::middleware($this->middleware())
                ->prefix($this->slug())
                ->name($this->slug().'.')
                ->group($this->path('routes.php'));
        }
    }
}
