#!/bin/bash
# SEO Аудитор — автонастройка сервера
# Запуск: ssh root@magnit365.ru 'bash -s' < setup_server.sh

set -e
DOMAIN="seo.magnit365.ru"
WEBROOT="/var/www/seo.magnit365.ru"
DB_NAME="seo_auditor"
DB_USER="seo_user"
DB_PASS=$(openssl rand -base64 16 | tr -d '/+=')

echo "=============================="
echo "  SEO Аудитор — настройка сервера"
echo "=============================="

# ── 1. Определяем веб-сервер ──────────────────────────────────────────────────
echo ""
echo "► Определяем веб-сервер..."

if systemctl is-active --quiet nginx 2>/dev/null; then
    WEBSERVER="nginx"
elif systemctl is-active --quiet apache2 2>/dev/null; then
    WEBSERVER="apache2"
elif systemctl is-active --quiet httpd 2>/dev/null; then
    WEBSERVER="httpd"
else
    echo "ВНИМАНИЕ: Не удалось определить активный веб-сервер. Проверяем установленные..."
    if command -v nginx &>/dev/null; then WEBSERVER="nginx"; systemctl start nginx;
    elif command -v apache2 &>/dev/null; then WEBSERVER="apache2"; systemctl start apache2;
    else echo "ОШИБКА: Нет nginx или apache2. Установите веб-сервер вручную."; exit 1; fi
fi
echo "  Веб-сервер: $WEBSERVER"

# ── 2. Определяем версию PHP и PHP-FPM socket ─────────────────────────────────
echo ""
echo "► Проверяем PHP..."
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.1")
echo "  PHP: $PHP_VERSION"

# Ищем сокет PHP-FPM
FPM_SOCK=""
for sock in /var/run/php/php${PHP_VERSION}-fpm.sock /var/run/php-fpm/php-fpm.sock /tmp/php-cgi.sock; do
    if [ -S "$sock" ]; then FPM_SOCK="$sock"; break; fi
done
[ -z "$FPM_SOCK" ] && FPM_SOCK="/var/run/php/php${PHP_VERSION}-fpm.sock"
echo "  PHP-FPM socket: $FPM_SOCK"

# ── 3. Создаём директорию проекта ─────────────────────────────────────────────
echo ""
echo "► Создаём директории..."
mkdir -p "$WEBROOT/public"
mkdir -p "$WEBROOT/logs"

# Заглушка index.php для проверки
cat > "$WEBROOT/public/index.php" << 'EOF'
<?php echo "SEO Аудитор — установка прошла успешно! (".phpversion().")"; ?>
EOF

if [ "$WEBSERVER" = "apache2" ] || [ "$WEBSERVER" = "httpd" ]; then
    chown -R www-data:www-data "$WEBROOT" 2>/dev/null || chown -R apache:apache "$WEBROOT" 2>/dev/null || true
else
    chown -R www-data:www-data "$WEBROOT" 2>/dev/null || chown -R nginx:nginx "$WEBROOT" 2>/dev/null || true
fi
echo "  Создано: $WEBROOT"

# ── 4. Настраиваем вертуальный хост ──────────────────────────────────────────
echo ""
echo "► Настраиваем виртуальный хост ($WEBSERVER)..."

if [ "$WEBSERVER" = "nginx" ]; then

    # Определяем где лежат конфиги nginx
    if [ -d /etc/nginx/sites-available ]; then
        NGINX_CONF="/etc/nginx/sites-available/$DOMAIN"
        NGINX_LINK="/etc/nginx/sites-enabled/$DOMAIN"
    else
        NGINX_CONF="/etc/nginx/conf.d/$DOMAIN.conf"
        NGINX_LINK=""
    fi

    cat > "$NGINX_CONF" << NGINXEOF
server {
    listen 80;
    server_name $DOMAIN;
    root $WEBROOT/public;
    index index.php index.html;

    access_log $WEBROOT/logs/access.log;
    error_log  $WEBROOT/logs/error.log;

    client_max_body_size 20M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PHP_VALUE "upload_max_filesize=20M \n post_max_size=20M";
        fastcgi_read_timeout 300;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(jpg|jpeg|gif|png|svg|css|js|ico|woff|woff2|ttf)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
    }
}
NGINXEOF

    # Создаём симлинк если нужен
    [ -n "$NGINX_LINK" ] && ln -sf "$NGINX_CONF" "$NGINX_LINK"

    nginx -t && systemctl reload nginx
    echo "  Nginx vhost создан: $NGINX_CONF"

elif [ "$WEBSERVER" = "apache2" ] || [ "$WEBSERVER" = "httpd" ]; then

    APACHE_CONF="/etc/apache2/sites-available/$DOMAIN.conf"
    [ "$WEBSERVER" = "httpd" ] && APACHE_CONF="/etc/httpd/conf.d/$DOMAIN.conf"

    cat > "$APACHE_CONF" << APACHEEOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $WEBROOT/public

    <Directory $WEBROOT/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    <FilesMatch \.php$>
        SetHandler application/x-httpd-php
    </FilesMatch>

    CustomLog $WEBROOT/logs/access.log combined
    ErrorLog  $WEBROOT/logs/error.log
</VirtualHost>
APACHEEOF

    if [ "$WEBSERVER" = "apache2" ]; then
        a2enmod rewrite php${PHP_VERSION} 2>/dev/null || true
        a2ensite "$DOMAIN" 2>/dev/null || true
        apache2ctl configtest && systemctl reload apache2
    else
        httpd -t && systemctl reload httpd
    fi
    echo "  Apache vhost создан: $APACHE_CONF"
fi

# ── 5. Устанавливаем Certbot и получаем SSL ───────────────────────────────────
echo ""
echo "► Устанавливаем SSL (Let's Encrypt)..."

if ! command -v certbot &>/dev/null; then
    echo "  Устанавливаем certbot..."
    if command -v apt-get &>/dev/null; then
        apt-get update -qq
        apt-get install -y certbot python3-certbot-nginx python3-certbot-apache -qq
    elif command -v yum &>/dev/null; then
        yum install -y certbot python3-certbot-nginx python3-certbot-apache -q
    fi
fi

if [ "$WEBSERVER" = "nginx" ]; then
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email admin@magnit365.ru --redirect
elif [ "$WEBSERVER" = "apache2" ] || [ "$WEBSERVER" = "httpd" ]; then
    certbot --apache -d "$DOMAIN" --non-interactive --agree-tos --email admin@magnit365.ru --redirect
fi

echo "  SSL установлен для $DOMAIN"

# ── 6. Настраиваем MySQL ──────────────────────────────────────────────────────
echo ""
echo "► Создаём базу данных MySQL..."

mysql << SQLEOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQLEOF

echo "  БД создана: $DB_NAME"

# ── 7. Настраиваем cron для worker ───────────────────────────────────────────
echo ""
echo "► Настраиваем cron для воркера..."
CRON_LINE="* * * * * php $WEBROOT/jobs/worker.php >> $WEBROOT/logs/worker.log 2>&1"
(crontab -l 2>/dev/null | grep -v "worker.php"; echo "$CRON_LINE") | crontab -
echo "  Cron добавлен: каждую минуту"

# ── 8. Устанавливаем Composer ─────────────────────────────────────────────────
echo ""
echo "► Проверяем Composer..."
if ! command -v composer &>/dev/null; then
    echo "  Устанавливаем Composer..."
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer -q
    rm composer-setup.php
fi
echo "  Composer: $(composer --version 2>&1 | head -1)"

# ── 9. Итог ───────────────────────────────────────────────────────────────────
echo ""
echo "=============================="
echo "  ГОТОВО! Сохраните данные:"
echo "=============================="
echo ""
echo "  URL:          https://$DOMAIN"
echo "  Директория:   $WEBROOT"
echo "  Веб-сервер:   $WEBSERVER"
echo "  PHP:          $PHP_VERSION"
echo ""
echo "  БД хост:      localhost"
echo "  БД имя:       $DB_NAME"
echo "  БД пользователь: $DB_USER"
echo "  БД пароль:    $DB_PASS"
echo ""
echo "  Логи:         $WEBROOT/logs/"
echo "=============================="
