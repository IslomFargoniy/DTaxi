#!/usr/bin/env bash
# ibTaxi o'rnatish skripti — toza Ubuntu 24.04 LTS uchun.
#
#   sudo ./install.sh --dev        lokal virtual mashina (Multipass): kod Mac'dan ulangan papkada
#   sudo ./install.sh --prod       xaridor serveri (keyingi bosqichlarda kengaytiriladi)
#
# Skript qayta ishga tushirilsa ham xavfsiz (idempotent).
set -euo pipefail

MODE="${1:---dev}"
APP_DIR="${APP_DIR:-/home/ubuntu/ibTaxi}"
APP_USER="${APP_USER:-ubuntu}"
DB_NAME="${DB_NAME:-ibtaxi}"
DB_USER="${DB_USER:-ibtaxi}"
DB_PASS="${DB_PASS:-ibtaxi}"
PHP_VERSION="8.3"
PG_VERSION="16"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Root sifatida ishga tushiring: sudo $0 $*" >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

log "Tizim paketlari"
# Yangi serverda avtomatik yangilanishlar apt'ni band qilib turadi — bo'shashini kutamiz
apt() { command apt-get -o DPkg::Lock::Timeout=600 "$@"; }
apt update -q
apt install -y -q ca-certificates curl gnupg unzip git acl \
    nginx supervisor redis-server \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" \
    "php${PHP_VERSION}-redis" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-gd" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-sqlite3" \
    "postgresql-${PG_VERSION}" "postgresql-${PG_VERSION}-postgis-3"

log "Composer"
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "Node.js 22"
if ! command -v node >/dev/null || [[ "$(node -v)" != v22* ]]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt install -y -q nodejs
fi

log "PostgreSQL: baza va foydalanuvchi"
sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 ||
    sudo -u postgres psql -c "CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}' CREATEDB"
for db in "${DB_NAME}" "${DB_NAME}_test"; do
    sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1 ||
        sudo -u postgres createdb -O "${DB_USER}" "${db}"
    sudo -u postgres psql -d "${db}" -c "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
done

PG_CONF_DIR="/etc/postgresql/${PG_VERSION}/main"
REDIS_CONF="/etc/redis/redis.conf"

if [[ "$MODE" == "--dev" ]]; then
    log "Dev: PostgreSQL va Redis Mac'dan ulanish uchun ochiladi"
    sed -i "s/^#\?listen_addresses.*/listen_addresses = '*'/" "${PG_CONF_DIR}/postgresql.conf"
    grep -q "ibtaxi-dev" "${PG_CONF_DIR}/pg_hba.conf" ||
        echo "host all ${DB_USER} 0.0.0.0/0 scram-sha-256 # ibtaxi-dev" >> "${PG_CONF_DIR}/pg_hba.conf"
    sed -i 's/^bind .*/bind 0.0.0.0 ::/' "${REDIS_CONF}"
    sed -i 's/^protected-mode yes/protected-mode no/' "${REDIS_CONF}"

    # Ulangan papkadagi fayllar ubuntu foydalanuvchisiga tegishli — php-fpm ham shu nomdan ishlaydi
    sed -i "s/^user = .*/user = ${APP_USER}/; s/^group = .*/group = ${APP_USER}/" \
        "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    # Nginx (www-data) /home/ubuntu ichiga kira olishi uchun
    usermod -aG "${APP_USER}" www-data
fi

systemctl restart postgresql redis-server "php${PHP_VERSION}-fpm"

log "Nginx"
cat > /etc/nginx/sites-available/ibtaxi <<NGINX
server {
    listen 80 default_server;
    server_name _;
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 20M;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Reverb (WebSocket): panel va haydovchi ilovasi bir xil domen orqali ulanadi
    location /app {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }

    # Xarita tile'lari: katta fayl, Range so'rovlari bilan qismlab beriladi
    location /map/ {
        add_header Cache-Control "public, max-age=86400";
        # style.json — Laravel yig'adi (server manzili bilan)
        try_files \$uri /index.php?\$query_string;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/ibtaxi /etc/nginx/sites-enabled/ibtaxi
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

log "Fon xizmatlari: Reverb, Horizon, scheduler"
REVERB_HOST_BIND="127.0.0.1"
[[ "$MODE" == "--dev" ]] && REVERB_HOST_BIND="0.0.0.0"
cat > /etc/supervisor/conf.d/ibtaxi.conf <<SUPERVISOR
[program:ibtaxi-reverb]
command=php ${APP_DIR}/artisan reverb:start --host=${REVERB_HOST_BIND} --port=8080
user=${APP_USER}
autostart=true
autorestart=true
stopwaitsecs=10
redirect_stderr=true
stdout_logfile=/var/log/ibtaxi-reverb.log

[program:ibtaxi-horizon]
command=php ${APP_DIR}/artisan horizon
user=${APP_USER}
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/ibtaxi-horizon.log
SUPERVISOR
echo "* * * * * ${APP_USER} php ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/ibtaxi
supervisorctl reread
supervisorctl update

log "Tayyor"
echo "PostgreSQL: ${DB_NAME} / ${DB_USER}  (test bazasi: ${DB_NAME}_test)"
echo "Panel: http://$(hostname -I | awk '{print $1}')"
