# syntax=docker/dockerfile:1

FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
        librdkafka-dev \
        libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl intl \
    && pecl channel-update pecl.php.net \
    && { pecl install rdkafka || pecl install rdkafka || pecl install rdkafka-6.0.5; } \
    && docker-php-ext-enable rdkafka \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-security-blocking \
    || composer update --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-security-blocking

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p var/cache var/log \
    && chmod -R 777 var

ENV APP_ENV=prod \
    APP_DEBUG=0

CMD ["php", "bin/console", "app:consume-user-events", "-v"]
