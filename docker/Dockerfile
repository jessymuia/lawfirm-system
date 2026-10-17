# Local development/testing image for the docker-compose stack — NOT a
# production build. The application source is bind-mounted at runtime, so
# this image only provides the PHP runtime, extensions, and Composer.
FROM php:8.3-cli-bookworm

# install-php-extensions resolves the system build deps for each extension.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath gd zip intl exif pcntl @composer

# git + unzip for Composer; the mysql client gives backup:run a real
# mysqldump and makes `docker compose exec app mysql ...` debugging easy.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
# --no-reload is load-bearing: without it, `artisan serve` strips almost all
# environment variables from the child server process whenever a .env file
# exists (its .env hot-reload mechanism), which silently discards the
# compose-provided DB_HOST/DB_PASSWORD/Sanctum settings and makes the server
# read .env's 127.0.0.1 instead — "Connection refused" on every query.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
