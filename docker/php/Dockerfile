FROM node:22-bookworm-slim AS node

FROM php:8.4-fpm-bookworm AS development

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_pgsql sockets zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
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
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]

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
RUN npx playwright install-deps chromium

FROM nginx:1.27-alpine AS quality-nginx
COPY --from=quality /var/www/html/public /var/www/html/public
COPY docker/quality/nginx.conf /etc/nginx/conf.d/default.conf
