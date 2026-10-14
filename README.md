<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Files d'attente et Horizon

Les scans de l'Agenda tournent en tâche de fond (un job par source). Il faut donc toujours un worker qui traite la file d'attente.

| | Local (WAMP, Windows) | Production (serveur Linux) |
|---|---|---|
| File d'attente | `database` | `redis` |
| Worker | `php artisan queue:work` | Horizon |
| Tableau de bord | aucun | `/horizon` |

> Horizon ne tourne pas sous Windows : il lui faut les extensions PHP `pcntl` et `posix`, et un serveur Redis. En local, n'ouvrez pas `/horizon` : sans Redis, la page plante.

### En local

Gardez `QUEUE_CONNECTION=database` dans `.env` et laissez tourner, dans un terminal :

```bash
php artisan queue:work
```

Après une modification du code d'un job, relancez la commande (le worker garde l'ancien code en mémoire).

### En production

**1. Prérequis**

- PHP avec les extensions `pcntl`, `posix` et `redis` (phpredis) ;
- un serveur Redis accessible ;
- Supervisor, pour garder Horizon en vie.

**2. Variables `.env`**

```dotenv
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Supérieur au timeout des jobs défini dans config/horizon.php (300 s)
REDIS_QUEUE_RETRY_AFTER=330

# Workers en parallèle : Firecrawl accepte 2 requêtes simultanées en offre gratuite, 5 en Hobby
HORIZON_MAX_PROCESSES=2

# Accès à /horizon : vide = tout utilisateur connecté du hub, sinon liste d'e-mails
HORIZON_ADMINS=vous@exemple.fr
```

**3. Lancer Horizon avec Supervisor**

Créez `/etc/supervisor/conf.d/horizon.conf` (adaptez le chemin et l'utilisateur) :

```ini
[program:horizon]
process_name=%(program_name)s
command=php /var/www/dada-hub/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/dada-hub/storage/logs/horizon.log
stopwaitsecs=3600
```

Puis :

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start horizon
```

`stopwaitsecs` laisse aux jobs en cours le temps de se terminer quand Horizon s'arrête.

**4. Activer la planification**

Dans `routes/console.php`, décommentez la ligne `use Illuminate\Support\Facades\Schedule;` et les tâches :

- `horizon:snapshot` : métriques des graphiques d'Horizon, toutes les 5 minutes ;
- `agenda:scan` : scan des sources, chaque lundi à 6 h.

Ajoutez ensuite la tâche cron qui exécute le planificateur chaque minute (`crontab -e -u www-data`) :

```cron
* * * * * cd /var/www/dada-hub && php artisan schedule:run >> /dev/null 2>&1
```

Vérifiez avec `php artisan schedule:list`.

**5. À chaque déploiement**

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate
```

`optimize` met en cache la configuration, les routes (y compris celles des modules) et les vues. Après un changement de `.env` en production, relancez `php artisan optimize`. Horizon termine les jobs en cours puis s'arrête ; Supervisor le relance aussitôt avec le nouveau code.

### Commandes utiles

| Commande | Rôle |
|---|---|
| `php artisan horizon:status` | Horizon tourne-t-il ? |
| `php artisan horizon:pause` / `horizon:continue` | Suspendre / reprendre le traitement des jobs |
| `php artisan horizon:terminate` | Arrêt propre (relancé par Supervisor) |
| `php artisan queue:failed` | Lister les jobs en échec |
| `php artisan queue:retry all` | Relancer les jobs en échec |
| `php artisan agenda:scan` | Lancer un scan à la main (`--filter=musique`, `--force`, `--web`, `--batch`) |
| `php artisan agenda:dedupe` | Fusionner les événements en double (`--dry-run` pour seulement les lister) |

### Dépannage

- **Scan bloqué « en attente »** : aucun worker ne tourne. Vérifiez `horizon:status` (production) ou lancez `queue:work` (local).
- **Sources en échec « HTTP 429 »** : trop de requêtes simultanées vers Firecrawl. Baissez `HORIZON_MAX_PROCESSES`.
- **Erreur 403 sur `/horizon`** : votre e-mail n'est pas dans `HORIZON_ADMINS`.
- **`composer install` réclame `ext-pcntl` sous Windows** : ces extensions sont déclarées dans `config.platform` de `composer.json`. Ne retirez pas cette section.

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
