FROM php:8.3-apache-bookworm

ARG MOODLE_VERSION=5.2.1
ARG MOODLE_BRANCH=502
ARG MOODLE_SHA256=db1167f3deef899aef6d4b273d3bc901b102c5d9de2e97289492505c2ec29629

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        default-mysql-client \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        pigz \
        rsync \
        unzip \
        util-linux \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        exif \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        soap \
        zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    archive=/tmp/moodle.tgz; \
    mkdir -p /opt/moodle /var/www/moodledata; \
    curl -fsSL \
      --retry 5 \
      --retry-all-errors \
      --output "${archive}" \
      "https://download.moodle.org/download.php/direct/stable${MOODLE_BRANCH}/moodle-${MOODLE_VERSION}.tgz"; \
    printf '%s  %s\n' "${MOODLE_SHA256}" "${archive}" | sha256sum --check --strict -; \
    tar -xzf "${archive}" --strip-components=1 -C /opt/moodle; \
    rm -f "${archive}"; \
    for required_file in \
      /opt/moodle/lib/components.json \
      /opt/moodle/public/version.php \
      /opt/moodle/public/cache/classes/config.php \
      /opt/moodle/public/lib/classes/component.php \
      /opt/moodle/admin/cli/install.php \
      /opt/moodle/admin/cli/install_database.php \
      /opt/moodle/admin/cli/upgrade.php; \
    do \
      test -s "${required_file}"; \
    done; \
    grep -Eq "\\\$release[[:space:]]*=[[:space:]]*'${MOODLE_VERSION}( |')" \
      /opt/moodle/public/version.php; \
    grep -Eq "\\\$branch[[:space:]]*=[[:space:]]*'${MOODLE_BRANCH}'" \
      /opt/moodle/public/version.php; \
    grep -Eq '"cache"[[:space:]]*:[[:space:]]*"public/cache"' \
      /opt/moodle/lib/components.json; \
    printf '%s\n' "${MOODLE_VERSION}" > /opt/moodle/.distribution-version; \
    printf '%s\n' "${MOODLE_BRANCH}" > /opt/moodle/.distribution-branch; \
    printf '%s\n' "${MOODLE_SHA256}" > /opt/moodle/.distribution-sha256

# Cada checkout y sus submodules deben estar registrados con commit, versión,
# release si está declarado en version.php y hash del árbol.
COPY docker/custom-plugins/ /opt/custom-plugins/
COPY docker/custom-plugins/approved-plugins.json /opt/approved-plugins.json
COPY docker/verify-plugin-pins.php /usr/local/bin/verify-plugin-pins.php
RUN php /usr/local/bin/verify-plugin-pins.php /opt/custom-plugins /opt/approved-plugins.json \
    && rsync -a --exclude=.git --exclude=README-PLUGINS.txt \
      --exclude=approved-plugins.json \
      /opt/custom-plugins/ /opt/moodle/ \
    && rm -rf /opt/custom-plugins \
    && chown -R www-data:www-data /opt/moodle /var/www/moodledata

COPY docker/php.ini /usr/local/etc/php/conf.d/99-moodle-production.ini
COPY docker/configure-runtime.php /usr/local/lib/moodle-configure-runtime.php
COPY docker/managed-config.php /usr/local/lib/moodle-managed-config.php
COPY docker/entrypoint.sh /usr/local/bin/moodle-production-entrypoint

RUN chmod 0755 /usr/local/bin/moodle-production-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["moodle-production-entrypoint"]
CMD ["apache2-foreground"]
