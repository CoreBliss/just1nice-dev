#!/usr/bin/env bash
set -e

PORT="${PORT:-10000}"

cat > /etc/apache2/ports.conf <<EOF
Listen ${PORT}
EOF

cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${PORT}>
    ServerName localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
EOF

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

php artisan config:clear
php artisan cache:clear

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
  php artisan migrate --force
fi

php artisan config:cache
php artisan view:cache

exec apache2-foreground