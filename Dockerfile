# syntax=docker/dockerfile:1
# PLAT-5: production image. One image, several roles (APP_ROLE: web | queue | scheduler | migrate | artisan).
# Build:   docker build -t lawfirm:$(cat VERSION) .
# The local-testing image lives in docker/Dockerfile and is NOT this one.
ARG PHP_VERSION=8.3

# ---- 1. Frontend bundle -----------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js tsconfig.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- 2. PHP dependencies (no dev packages) + pruned source --------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
# Remove vendor-only tools and test code BEFORE the autoloader is built.
RUN sh scripts/prune-distribution.sh /app deploy/dist-excludes.txt \
 && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---- 3. Runtime -------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS runtime
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath gd zip intl exif pcntl opcache sodium redis \
 && apt-get update \
 && apt-get install -y --no-install-recommends nginx supervisor default-mysql-client curl util-linux \
    poppler-utils tesseract-ocr tesseract-ocr-eng \
 && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY docker/prod/nginx.conf /etc/nginx/nginx.conf
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/prod/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/prod/supervisord.conf /etc/supervisor/app-supervisord.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

ARG VERSION=dev
LABEL org.opencontainers.image.title="lawfirm" org.opencontainers.image.version="${VERSION}"
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr_json

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://127.0.0.1:8080/healthz || exit 1
ENTRYPOINT ["app-entrypoint"]
CMD ["web"]
