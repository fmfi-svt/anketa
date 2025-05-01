FROM php:5.5-apache AS base
COPY --from=composer/composer:2.2-bin /composer /usr/local/bin/
COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /usr/local/bin/
# For some reason the image does not come with php.ini, so it defaults to log_errors=Off. :C
ADD https://github.com/php/php-src/raw/refs/heads/PHP-5.5/php.ini-production /usr/local/etc/php/php.ini
RUN <<EOF
    set -e
    # jessie is too old and the default sources.list doesn't work.
    echo '
        deb http://archive.debian.org/debian/ jessie main
        deb-src http://archive.debian.org/debian/ jessie main
        deb http://archive.debian.org/debian-security/ jessie/updates main
        deb-src http://archive.debian.org/debian-security/ jessie/updates main
    ' > /etc/apt/sources.list
    # unzip and git are needed by composer.
    # libldap2-dev is needed by PHP ldap, installed below.
    # locales is needed for locale-gen below.
    # ca-certificates needs to be updated for connections to repo.packagist.org (it uses Let's Encrypt).
    apt-get update --allow-unauthenticated
    apt-get install -y --no-install-recommends --allow-unauthenticated unzip git libldap2-dev locales ca-certificates
    rm -rf /var/lib/apt/lists/*
    # For some reason the image does not come with ldap and pdo_mysql. This compiles them from source. :C
    docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/
    docker-php-ext-install pdo_mysql ldap
    docker-php-ext-enable pdo_mysql ldap
    # This is needed for setlocale() in Slugifier.php.
    echo 'en_US.UTF-8 UTF-8' >> /etc/locale.gen
    locale-gen
    # Tweak Apache configuration.
    a2enmod rewrite
    sed -ri '/AllowOverride All/ d' /etc/apache2/conf-available/docker-php.conf
    sed -ri '
        s!DocumentRoot /var/www/html!DocumentRoot /var/www/anketa/web!
        /<\/VirtualHost>/ i \
            RewriteEngine On \
            RewriteCond /var/www/anketa/web/$1 !-f \
            RewriteRule ^(.*)$ /app.php [QSA,L]
    ' /etc/apache2/sites-available/000-default.conf
    # Tweak PHP configuration. PHP loudly complains if timezone is not set.
    echo '
        error_reporting = E_ALL
        date.timezone = Europe/Bratislava
    ' > /usr/local/etc/php/conf.d/custom.ini
EOF
WORKDIR /var/www/anketa
EXPOSE 80
CMD ["apache2-foreground"]

FROM base AS dev
RUN sed -ri 's/app.php/app_logindev.php/ ; /<\/VirtualHost>/ i SetEnv ALLOW_APP_LOGINDEV 1' /etc/apache2/sites-available/000-default.conf

# This is just a hypothetical example for now, because anketa production does
# not use docker.
# This `prod` image includes project code and vendor/. The `dev` image does not,
# because it's used with `.` mounted as a volume, so adding the code inside the
# image too would be needless duplication.
FROM base AS prod
# We can't do the 3-step "COPY deps info, RUN install, COPY the rest" layer
# optimization, because symfony postinstall scripts need app/+src/.
COPY . .
RUN scripts/init_all.sh --www-user=www-data --skip-advice --mysql=db,anketa,anketa,anketa
