FROM debian:bookworm-slim

COPY --from=composer/composer:2.2-bin /composer /usr/local/bin/composer
COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && curl -fsSLo /tmp/debsuryorg-archive-keyring.deb \
        https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && rm /tmp/debsuryorg-archive-keyring.deb \
    && echo 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main' \
        > /etc/apt/sources.list.d/php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 \
        git \
        unzip \
        libapache2-mod-php5.6 \
        php5.6-cli \
        php5.6-curl \
        php5.6-ldap \
        php5.6-mbstring \
        php5.6-mysql \
        php5.6-sqlite3 \
        php5.6-xml \
        locales \
    && echo 'en_US.UTF-8 UTF-8' >> /etc/locale.gen \
    && locale-gen \
    && a2enmod rewrite \
    && echo 'date.timezone = Europe/Bratislava' > /etc/php/5.6/mods-available/anketa.ini \
    && phpenmod -v 5.6 anketa \
    && rm -rf /var/lib/apt/lists/*

# Keep uv's Python interpreter accessible to Apache's www-data user.
ENV UV_PYTHON_INSTALL_DIR=/opt/python

WORKDIR /var/www/html

COPY . .

# Symfony's install scripts need a config, but real credentials are mounted at runtime.
RUN cp app/config/config_local.yml.dist app/config/config_local.yml \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction \
    && rm app/config/config_local.yml \
    && rm -rf app/cache/* \
    && mkdir -p app/cache app/logs \
    && chown -R www-data:www-data app/cache app/logs

EXPOSE 80
CMD ["/bin/bash", "/var/www/html/docker/apache-entrypoint.sh"]
