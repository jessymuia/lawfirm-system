#!/bin/sh
# PLAT-5: role selector for the production image.
#   web        nginx + php-fpm (default)
#   queue      php artisan queue:work (lanes: QUEUE_WORKER_QUEUES, default "default,bulk")
#   scheduler  php artisan schedule:work
#   migrate    one-shot: php artisan app:install (migrate + permissions/roles + first admin if none)
#   artisan …  run any artisan command, e.g.  docker run … artisan license:install /path
# Migrations never run implicitly in web/queue/scheduler, so several replicas can start without racing.
set -eu

ROLE="${1:-web}"
[ $# -gt 0 ] && shift || true

as_app() { runuser -u www-data -- "$@"; }

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker run --rm <image> artisan key:generate --show" >&2
    exit 1
fi

cd /var/www/html

# Fresh volumes mask the image's storage/ tree: recreate what Laravel needs.
mkdir -p storage/app storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

wait_for_db() {
    i=0
    until php -r '
        try {
            new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
        } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }' 2>/dev/null; do
        i=$((i + 1))
        [ "$i" -ge "${DB_WAIT_SECONDS:-60}" ] && { echo "database not reachable after ${DB_WAIT_SECONDS:-60}s" >&2; exit 1; }
        sleep 1
    done
}

cache_config() {
    # Environment is only known at runtime, so caches are built here, not in the image.
    as_app php artisan config:cache
    as_app php artisan route:cache
    as_app php artisan view:cache
    [ -e public/storage ] || as_app php artisan storage:link || true
}

case "$ROLE" in
    web)
        cache_config
        exec /usr/bin/supervisord -c /etc/supervisor/app-supervisord.conf
        ;;
    queue)
        wait_for_db; cache_config
        # ENG-20: which lanes this worker serves, first listed first. The default serves interactive work before bulk;
        # scale out by running more queue containers, or give bulk its own (QUEUE_WORKER_QUEUES=bulk, QUEUE_WORKER_TIMEOUT=3700).
        exec runuser -u www-data -- php artisan queue:work --queue="${QUEUE_WORKER_QUEUES:-default,bulk}" --tries=3 --sleep=3 \
            --timeout="${QUEUE_WORKER_TIMEOUT:-3700}" --max-time=3600
        ;;
    scheduler)
        wait_for_db; cache_config
        exec runuser -u www-data -- php artisan schedule:work
        ;;
    migrate)
        wait_for_db
        exec runuser -u www-data -- php artisan app:install
        ;;
    artisan)
        exec runuser -u www-data -- php artisan "$@"
        ;;
    *)
        exec "$ROLE" "$@"
        ;;
esac
