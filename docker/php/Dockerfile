FROM node:22-bookworm-slim AS node

FROM php:8.4-fpm-bookworm AS development

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        util-linux \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_pgsql sockets zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && command -v prlimit \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html

# Keep dependency layers reusable while application source changes.
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# The application must exist before Laravel package discovery runs.
RUN mkdir -p storage/app/private storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]

# Reproducible synthetic source; never used by the ordinary development profile.
FROM node AS synthetic-moodle-code
RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
COPY --from=development /etc/ssl/certs /etc/ssl/certs
RUN git -c http.version=HTTP/1.1 -c http.lowSpeedLimit=1024 -c http.lowSpeedTime=120 \
        clone --quiet --depth 1 --branch v4.5.10 https://github.com/moodle/moodle.git /moodle \
    && test "$(git -C /moodle rev-parse HEAD)" = "b2c2f2a0c5f9a141896df6c322a0b6b29bb77646" \
    && rm -rf /moodle/.git

FROM php:8.3-cli-bookworm AS collector-php
RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pgsql pdo_pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

FROM development AS collector-lab
RUN apt-get update && apt-get install -y --no-install-recommends libfreetype6 libjpeg62-turbo libpng16-16 && rm -rf /var/lib/apt/lists/*
COPY --from=collector-php /usr/local/bin/php /opt/collector-php/bin/php
COPY --from=collector-php /usr/local/lib/php/extensions /opt/collector-php/extensions
COPY --from=collector-php /usr/local/etc/php/conf.d /opt/collector-php/conf.d
COPY --from=synthetic-moodle-code /moodle /opt/moodle-lab/code
RUN printf 'extension_dir=/opt/collector-php/extensions/no-debug-non-zts-20230831\nmax_input_vars=5000\nmemory_limit=512M\n' > /opt/collector-php/php.ini \
    && PHP_INI_SCAN_DIR=/opt/collector-php/conf.d /opt/collector-php/bin/php -c /opt/collector-php/php.ini -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 && extension_loaded("gd") && extension_loaded("pgsql") && extension_loaded("zip") ? 0 : 1);'

# Immutable quality image: no host vendor/, node_modules/, .env or storage.
FROM development AS quality
RUN touch .env
ENV VITE_REVERB_APP_KEY=quality-key \
    VITE_REVERB_HOST=localhost \
    VITE_REVERB_PORT=8081 \
    VITE_REVERB_SCHEME=http
RUN npm run build

FROM mcr.microsoft.com/playwright:v1.63.0-noble AS browsers
FROM quality AS e2e
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
COPY --from=browsers /ms-playwright /ms-playwright
RUN npx playwright install-deps chromium && command -v runuser

FROM nginx:1.27-alpine AS quality-nginx
COPY --from=quality /var/www/html/public /var/www/html/public
COPY docker/quality/nginx.conf /etc/nginx/conf.d/default.conf
