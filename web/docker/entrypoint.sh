#!/bin/sh
# Запуск контейнеров сайта, очереди и планировщика (один образ, разные команды).
set -e

# настройки — из переменных окружения docker-compose (файла .env в образе нет);
# кэш конфигурации нужен и серверу: `artisan serve` передаёт PHP только часть переменных окружения
php artisan config:cache --no-interaction >/dev/null

# миграции выполняет только контейнер сайта; очередь и планировщик ждут его готовности (depends_on)
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
