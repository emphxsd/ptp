# Веб-приложение (Laravel). Один образ для сайта, очереди и планировщика — команда задаётся в docker-compose.yml.

# --- PHP с расширениями: PostgreSQL, pcntl (таймауты задач очереди), zip (Composer); opcache в PHP 8.5 встроен
FROM php:8.5-cli AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www

# --- зависимости PHP (без пакетов для разработки)
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# --- сборка интерфейса (стили Flux берутся из vendor)
FROM node:24-bookworm-slim AS assets
WORKDIR /var/www
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www /var/www
RUN npm run build

# --- итоговый образ
FROM base
COPY --from=vendor --chown=www-data:www-data /var/www /var/www
COPY --from=assets --chown=www-data:www-data /var/www/public/build /var/www/public/build
RUN mkdir -p storage/app/private/documents storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

USER www-data
# встроенный сервер PHP в несколько процессов: загрузки и опрос страницы не ждут друг друга
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
