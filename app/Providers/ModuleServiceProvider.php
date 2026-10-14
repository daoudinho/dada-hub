<?php

namespace App\Providers;

use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Enregistre les sous-projets listés dans config/hub.php.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        $registry = $this->app->make(ModuleRegistry::class);

        foreach (config('hub.modules', []) as $class) {
            $module = $this->app->register($class);

            if ($module instanceof Module) {
                $registry->add($module);
            }
        }
    }
}
