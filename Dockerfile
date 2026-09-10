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

# Realtime, compiled in.
#
# Vite inlines `import.meta.env.*` at BUILD time, so these have to be present
# here and cannot be supplied as runtime variables later: resources/js/echo.js
# only constructs window.Echo when a key is set, so an image built without one
# ships a browser bundle that never subscribes to anything, whatever the
# server is configured to broadcast.
#
# Only the PUBLIC key belongs in this stage. REVERB_APP_SECRET must never be
# passed here — everything in this stage is readable in the shipped JS.
#
# Empty is a working default, not a broken one: the build succeeds and the
# application runs exactly as it does with realtime switched off.
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"

ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build


###############################################################################
# Stage 3 - runtime
###############################################################################
FROM php:8.3-fpm-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    PORT=8080

# ---------------------------------------------------------------------------
# PHP extensions.
#
# `gd` is the one worth explaining. It is what makes an attached screenshot
# work: App\Services\AI\Attachments\ImageProcessor decodes every uploaded image
# and re-encodes it before it is ever sent to a model, which strips whatever was
# appended after the image data, strips EXIF (routinely including the GPS
# coordinates of where a photo was taken), and makes the declared media type
# true by construction because this application wrote both the bytes and the
# label.
#
# Without it that processor refuses politely and every image attachment reports
# itself unreadable. It is not in php:8.3-fpm-alpine, and its absence does not
# show up in the test suite because a developer's PHP almost always has it — so
# the build asserts it at the end, the same way git and node are checked below.
#
# PNG and GIF come with gd; JPEG and WebP have to be asked for, and the
# assistant's allow-list accepts all four.
#
# Comments stay out of the RUN body below. Docker does strip a full-line comment
# inside a line continuation, but a `#` that ends up mid-line after the newlines
# are joined would swallow the rest of the command — and a build that silently
# drops half an `apk add` list is not a failure anybody notices until runtime.
# ---------------------------------------------------------------------------
RUN set -eux; \
    apk add --no-cache \
        nginx \
        supervisor \
        curl \
        icu-libs \
        libzip \
        tzdata \
        libpng \
        libjpeg-turbo \
        libwebp; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/*; \
    php -m | grep -qx gd

# ---------------------------------------------------------------------------
# Worker tooling: git for the isolated checkout, Claude Code for apply mode.
#
# Neither is used by the web service. Both are in the shared image because the
# queue worker runs the SAME image with a different start command, and an apply
# run refuses immediately without them.
#
# Alpine is musl, so Claude Code needs libgcc, libstdc++ and a real ripgrep
# rather than its bundled glibc-linked copy. USE_BUILTIN_RIPGREP=0 is passed to
# the CLI by App\Services\AI\CodeGeneration\ClaudeCodeGenerator.
#
# node and npm are here for AI_VALIDATION_COMMANDS, which runs the target
# repository's own build inside the checkout before apply mode pushes anything.
# The assets stage above cannot supply them: only public/build is copied out of
# it, so nothing from that stage exists at runtime. Without them a validation
# command fails with `sh: npm: not found` AFTER the model has already done its
# work — the run is refused at the last step, having spent the tokens.
# ---------------------------------------------------------------------------
RUN set -eux; \
    grep -q '/community' /etc/apk/repositories || \
        echo "https://dl-cdn.alpinelinux.org/alpine/v$(cut -d. -f1,2 /etc/alpine-release)/community" \
            >> /etc/apk/repositories; \
    wget -qO /etc/apk/keys/claude-code.rsa.pub \
        https://downloads.claude.ai/keys/claude-code.rsa.pub; \
    echo "https://downloads.claude.ai/claude-code/apk/stable" >> /etc/apk/repositories; \
    apk add --no-cache git bash libgcc libstdc++ ripgrep claude-code nodejs npm; \
    rm -rf /var/cache/apk/*; \
    git --version; \
    node --version; \
    npm --version; \
    claude --version

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

# ---------------------------------------------------------------------------
# Writable directories.
#
# storage and bootstrap/cache are the ordinary Laravel ones. /var/cache/nginx is
# where nginx spills to disk: a request body larger than client_body_buffer_size
# (16k by default — so any uploaded file) is buffered to a file under
# client_body_temp_path, and a FastCGI response larger than fastcgi_buffers
# likewise.
#
# Those have to be writable by www-data, because that is the user
# docker/nginx.conf gives the worker processes. The Alpine package's own
# /var/lib/nginx/tmp is owned by the `nginx` user instead, and inheriting it
# meant every file upload returned 500 while every ordinary request worked —
# which in a Livewire app looks like the file simply vanishing, since a failed
# upload leaves no error on the page.
#
# Created here rather than left to nginx: nginx chowns a temp path it creates
# itself, but silently uses a pre-existing one as-is. docker/entrypoint.sh
# repeats it at boot, the same way it recreates the storage tree.
# ---------------------------------------------------------------------------
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
    chmod -R 775 storage bootstrap/cache; \
    mkdir -p \
        /var/cache/nginx/client_body \
        /var/cache/nginx/fastcgi; \
    chown -R www-data:www-data /var/cache/nginx; \
    chmod -R 700 /var/cache/nginx

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/up" || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
