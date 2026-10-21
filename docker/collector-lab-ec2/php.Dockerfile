# PHP 8.3 CLI for the synthetic source on EC2. Same extensions as the local LAB
# (docker/php/Dockerfile, stage collector-php); Moodle 4.5 does not support the
# PHP version shipped by recent Ubuntu releases.
FROM php:8.3-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pgsql pdo_pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && printf 'max_input_vars=5000\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/moodle-lab.ini \
    && php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 && extension_loaded("gd") && extension_loaded("pgsql") && extension_loaded("zip") ? 0 : 1);'
