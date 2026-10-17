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

log "Telefoniya: Asterisk (PJSIP Realtime, ARI, WebRTC)"
apt install -y -q asterisk asterisk-modules unixodbc odbc-postgresql ffmpeg openssl
ARI_PASSWORD="$(grep -s '^ASTERISK_ARI_PASSWORD=' "${APP_DIR}/.env" | cut -d= -f2 || true)"
[[ -z "$ARI_PASSWORD" ]] && ARI_PASSWORD="$(openssl rand -hex 16)"
PUBLIC_IP="${PUBLIC_IP:-$(hostname -I | awk '{print $1}')}"
for f in "${APP_DIR}"/deploy/asterisk/*.conf; do
    name="$(basename "$f")"
    sed -e "s|{{DB_NAME}}|${DB_NAME}|g" -e "s|{{DB_USER}}|${DB_USER}|g" -e "s|{{DB_PASS}}|${DB_PASS}|g" \
        -e "s|{{ARI_PASSWORD}}|${ARI_PASSWORD}|g" -e "s|{{PUBLIC_IP}}|${PUBLIC_IP}|g" \
        -e "s|{{LOCAL_NET}}|${LOCAL_NET:-192.168.0.0/16}|g" -e "s|{{STUN}}|${STUN:-stun.l.google.com:19302}|g" \
        "$f" > "/etc/asterisk/${name}"
done
sed -e "s|{{DB_NAME}}|${DB_NAME}|g" -e "s|{{DB_USER}}|${DB_USER}|g" -e "s|{{DB_PASS}}|${DB_PASS}|g" \
    "${APP_DIR}/deploy/asterisk/odbc.ini" > /etc/odbc.ini
# WebRTC media shifrlash (DTLS) sertifikati — o'z-o'zidan imzolangan yetarli (barmoq izi orqali tekshiriladi)
mkdir -p /etc/asterisk/keys
if [[ ! -f /etc/asterisk/keys/asterisk.pem ]]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=ibtaxi" \
        -keyout /etc/asterisk/keys/asterisk.key -out /etc/asterisk/keys/asterisk.crt 2>/dev/null
    cat /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.crt > /etc/asterisk/keys/asterisk.pem
fi
chown -R asterisk:asterisk /etc/asterisk
# Asterisk ovozli bo'laklarni o'qiydi, ilova esa yozuvlarni (recording) o'qiydi
usermod -aG "${APP_USER}" asterisk
usermod -aG asterisk "${APP_USER}"
mkdir -p "${APP_DIR}/storage/app/private/sounds/uz" /var/spool/asterisk/recording
chmod 2775 /var/spool/asterisk/recording
ln -sfn "${APP_DIR}/storage/app/private/sounds/uz" /usr/share/asterisk/sounds/ibtaxi
# Asterisk ovozi uzilmasligi uchun yuqori ustuvorlik (reja §9)
mkdir -p /etc/systemd/system/asterisk.service.d
printf '[Service]\nNice=-10\n' > /etc/systemd/system/asterisk.service.d/priority.conf
systemctl daemon-reload
systemctl enable asterisk
systemctl restart asterisk
if ! grep -q '^ASTERISK_ARI_PASSWORD=' "${APP_DIR}/.env" 2>/dev/null; then
    printf '\nASTERISK_ARI_URL=http://127.0.0.1:8088\nASTERISK_ARI_USER=ibtaxi\nASTERISK_ARI_PASSWORD=%s\n' "$ARI_PASSWORD" >> "${APP_DIR}/.env"
fi

if [[ "$MODE" == "--dev" ]]; then
    log "Dev: https (o'z-o'zidan imzolangan) — brauzer mikrofoni faqat https'da ishlaydi"
    if [[ ! -f /etc/ssl/ibtaxi-dev.crt ]]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=${PUBLIC_IP}" \
            -addext "subjectAltName=IP:${PUBLIC_IP}" \
            -keyout /etc/ssl/ibtaxi-dev.key -out /etc/ssl/ibtaxi-dev.crt 2>/dev/null
    fi
fi

log "Nginx"
DEV_TLS=""
[[ "$MODE" == "--dev" ]] && DEV_TLS="listen 443 ssl default_server; ssl_certificate /etc/ssl/ibtaxi-dev.crt; ssl_certificate_key /etc/ssl/ibtaxi-dev.key;"
cat > /etc/nginx/sites-available/ibtaxi <<NGINX
server {
    listen 80 default_server;
    ${DEV_TLS}
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

    # WebRTC SIP (operator brauzeri, haydovchi ilovasi) → Asterisk WebSocket
    location /ws {
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
        proxy_pass http://127.0.0.1:8088/ws;
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

[program:ibtaxi-ari]
command=php ${APP_DIR}/artisan ari:listen
user=${APP_USER}
autostart=true
autorestart=true
startsecs=3
stopwaitsecs=10
redirect_stderr=true
stdout_logfile=/var/log/ibtaxi-ari.log
SUPERVISOR
echo "* * * * * ${APP_USER} php ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/ibtaxi
supervisorctl reread
supervisorctl update

log "Tayyor"
echo "PostgreSQL: ${DB_NAME} / ${DB_USER}  (test bazasi: ${DB_NAME}_test)"
echo "Panel: http://$(hostname -I | awk '{print $1}')"
