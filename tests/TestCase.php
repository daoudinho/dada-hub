<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : les tests vident la base (RefreshDatabase). On refuse de démarrer si elle n'est
     * ni SQLite en mémoire, ni une base dont le nom finit par « _test » — par exemple quand une
     * configuration en cache (php artisan optimize) pointe vers la vraie base.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $url = (string) $app['config']->get("database.connections.{$connection}.url");
        $isTestDatabase = $database === ':memory:' || (str_ends_with($database, '_test') && $url === '');

        if (! $app->environment('testing') || ! $isTestDatabase) {
            throw new RuntimeException(
                "Tests arrêtés : ils videraient la base « {$connection} / {$database} ». "
                .'Utilisez SQLite en mémoire (phpunit.xml) ou une base « *_test ». '
                .'Si la configuration est en cache, lancez « php artisan config:clear ».'
            );
        }

        return $app;
    }
}
