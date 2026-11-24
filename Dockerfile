# Single stage: composer install first (Ziggy and the Vite plugin import from vendor), then build assets.
FROM serversideup/php:8.4-fpm-nginx
ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=false
USER root
RUN install-php-extensions bcmath pdo_pgsql
COPY --chown=www-data:www-data . /var/www/html
USER www-data
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
USER root
RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm && rm -rf /var/lib/apt/lists/*
USER www-data
RUN npm ci && npm run build && rm -rf node_modules
