<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Installation locale

Prérequis : WAMP (PHP 8.3 avec les extensions `pdo_pgsql` et `pgsql` activées), Composer, Node.js et Docker Desktop.

```bash
git clone <url-du-depot> dada-hub && cd dada-hub
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate

docker compose up -d          # PostgreSQL 17 sur le port 5432 (bases dada_hub et dada_hub_test)
php artisan migrate
php artisan db:seed --class="App\Modules\Agenda\Database\Seeders\AgendaSeeder"
php artisan db:seed --class="App\Modules\Agenda\Database\Seeders\AgendaSourceSeeder"
php artisan hub:user vous@exemple.fr
```

Le site est servi par WAMP sur `http://localhost/dada-hub/public` (`APP_URL` dans `.env`). Renseignez aussi les clés `OPENAI_API_KEY` et `FIRECRAWL_API_KEY`.

**Tests** : `php artisan test` tourne sur SQLite en mémoire (rapide, sans Docker). Pour les lancer sur PostgreSQL, comme en production :

```bash
DB_CONNECTION=pgsql DB_DATABASE=dada_hub_test DB_USERNAME=dada DB_PASSWORD=dada php artisan test
```

**Image de production** : `docker compose --profile app up -d --build` lance l'image du `Dockerfile` (celle de Render) sur `http://localhost:8080`, avec Apache et le worker de file d'attente dans le même conteneur.

## Files d'attente

Les scans de l'Agenda tournent en tâche de fond (un job par source). Les jobs sont stockés dans la base de données (table `jobs`, `QUEUE_CONNECTION=database`) : il faut un worker qui les exécute.

```bash
php artisan queue:work
```

- En local, laissez cette commande tourner dans un terminal pendant que vous utilisez l'Agenda.
- Après une modification du code d'un job, relancez-la : le worker garde l'ancien code en mémoire.
- Sans worker, un scan reste « en attente » ; la page le signale au bout de 15 secondes.

### Planification

Les tâches planifiées sont dans `routes/console.php`, commentées pour l'instant (scan des sources chaque lundi à 6 h, via l'API Batch). Pour les activer, décommentez-les avec la ligne `use Illuminate\Support\Facades\Schedule;`, puis :

- en local : `php artisan schedule:work` ;
- sur un serveur : une tâche cron qui exécute `php artisan schedule:run` chaque minute.

Vérifiez avec `php artisan schedule:list`.

### À chaque déploiement

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

`optimize` met en cache la configuration, les routes (y compris celles des modules) et les vues ; relancez-la après tout changement de `.env`. `queue:restart` demande au worker de se relancer avec le nouveau code une fois son job en cours terminé.

### Commandes utiles

| Commande | Rôle |
|---|---|
| `php artisan queue:work` | Exécuter les jobs en attente |
| `php artisan queue:failed` | Lister les jobs en échec |
| `php artisan queue:retry all` | Relancer les jobs en échec |
| `php artisan agenda:scan` | Lancer un scan à la main (`--filter=musique`, `--force`, `--web`, `--batch`) |
| `php artisan agenda:dedupe` | Fusionner les événements en double (`--dry-run` pour seulement les lister) |
| `php artisan hub:user email@exemple.fr` | Créer un compte ou changer son mot de passe |

### Dépannage

- **Scan bloqué « en attente »** : aucun worker ne tourne, lancez `php artisan queue:work`.
- **Sources en échec « HTTP 429 »** : trop de requêtes simultanées vers Firecrawl (2 en offre gratuite). Ne lancez qu'un seul worker.
- **Scan Batch « en attente du lot » depuis longtemps** : normal jusqu'à 24 h. Le worker doit tourner pour récupérer le résultat.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
