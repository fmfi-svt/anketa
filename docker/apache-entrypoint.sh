#!/bin/bash
set -e

# Empty for local root-mounted use; production examples use /fmph.
APP_BASE_PATH=${APP_BASE_PATH:-}
if [[ -n "$APP_BASE_PATH" && ! "$APP_BASE_PATH" =~ ^/[a-z]+$ ]]; then
    echo 'APP_BASE_PATH must be empty or a prefix such as /fmph (no trailing slash)' >&2
    exit 1
fi

{
    cat <<'APACHE'
<VirtualHost *:80>
    # These headers may ONLY come from the authenticating host proxy.
    SetEnvIf X-Anketa-User "^(.+)$" REMOTE_USER=$1
    SetEnvIf X-Anketa-Andrvotr-Authority-Token "^(.+)$" ANDRVOTR_AUTHORITY_TOKEN=$1
    # TLS always terminates at the public authentication proxy.
    SetEnv HTTPS on
APACHE
    if [[ -n "$APP_BASE_PATH" ]]; then
        echo '    DocumentRoot /var/www/empty'
        printf '    RedirectMatch 301 ^%s$ %s/\n' "$APP_BASE_PATH" "$APP_BASE_PATH"
        printf '    Alias %s/ /var/www/html/web/\n' "$APP_BASE_PATH"
    else
        echo '    DocumentRoot /var/www/html/web'
    fi
    cat <<'APACHE'
    <Directory /var/www/html/web>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        # Never expose development/login-bypass or setup entry points.
        <FilesMatch "^(app_dev|app_logindev|config)\.php$">
            Require all denied
        </FilesMatch>
    </Directory>
    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
APACHE
} > /etc/apache2/sites-available/000-default.conf

source /etc/apache2/envvars
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" /var/www/empty
rm -f "$APACHE_PID_FILE"
exec apache2 -D FOREGROUND
