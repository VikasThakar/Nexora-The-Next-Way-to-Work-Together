# syntax=docker/dockerfile:1

###############################################################################
# Stage 1 - PHP dependencies
###############################################################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Scripts are skipped here because artisan is not present yet; the autoloader
# is rebuilt against the full source tree in the runtime stage.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist


###############################################################################
# Stage 2 - front-end assets
###############################################################################
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
# Tailwind scans Blade templates and PHP sources for class names.
COPY app ./app
COPY config ./config

# resources/css/app.css declares @source paths outside resources/. They must
# resolve or the pagination styles silently drop out of the bundle.
COPY --from=vendor \
    /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
    ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN mkdir -p storage/framework/views

RUN npm run build


###############################################################################
# Stage 3 - runtime
###############################################################################
FROM php:8.3-fpm-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    PORT=8080

RUN set -eux; \
    apk add --no-cache \
        nginx \
        supervisor \
        curl \
        icu-libs \
        libzip \
        tzdata; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# Application source, then the artefacts built in the earlier stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-scripts; \
    rm -rf tests phpunit.xml; \
    mkdir -p storage/framework/cache/data; \
    mkdir -p storage/framework/sessions; \
    mkdir -p storage/framework/views; \
    mkdir -p storage/app/private; \
    mkdir -p storage/logs; \
    mkdir -p bootstrap/cache; \
    chmod +x /usr/local/bin/entrypoint; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/up" || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
