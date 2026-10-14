#!/usr/bin/env sh
set -e

cd /var/www/html

# Commandes artisan sous l'utilisateur d'Apache, pour que les fichiers de cache lui appartiennent.
artisan() {
    su -s /bin/sh www-data -c "php artisan $*"
}

# Dossiers en écriture pour Laravel (cache, sessions, vues compilées, logs).
mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Render : faire remonter les logs Apache dans le tableau de bord (stdout/stderr).
ln -sf /dev/stdout /var/log/apache2/access.log
ln -sf /dev/stderr /var/log/apache2/error.log

artisan migrate --force
# Ne remplit que ce qui est vide (sources, événements de départ, premier compte) : voir DatabaseSeeder.
artisan db:seed --force

# Config, routes (y compris celles des modules) et vues en cache, avec les variables d'environnement du conteneur.
artisan optimize

# Worker de file d'attente (scans de l'Agenda) dans le même conteneur : pas de « Background Worker »
# payant sur Render. La boucle le relance s'il s'arrête ; --max-time le recycle toutes les heures.
(
    while true; do
        artisan queue:work --sleep=3 --tries=1 --timeout=280 --max-time=3600 || true
        sleep 5
    done
) &

exec apache2-foreground
