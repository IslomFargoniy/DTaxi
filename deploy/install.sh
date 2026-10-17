#!/usr/bin/env bash
# ibTaxi o'rnatish skripti — toza Ubuntu 24.04 LTS uchun.
#
#   sudo ./install.sh --dev
#       lokal virtual mashina (Multipass): kod Mac'dan ulangan papkada, bazaga Mac'dan ulanadi
#
#   sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz
#       xaridor serveri: kod shu repozitoriy papkasida (masalan /var/www/ibtaxi), Let's Encrypt,
#       ufw, fail2ban, kunlik zaxira. Tugagach brauzerda https://<domen> — sozlash ustasi.
#       Server IP'si domen DNS'ida ko'rsatilgan bo'lishi kerak (Let's Encrypt tekshiradi).
#       Xizmat hududi: BBOX=lon1,lat1,lon2,lat2 (standart — Farg'ona vodiysi), xarita va marshrutlar shu hudud uchun.
#
# Skript qayta ishga tushirilsa ham xavfsiz (idempotent): mavjud parollar va .env saqlanadi.
set -euo pipefail

MODE="--dev"
DOMAIN=""
EMAIL=""
SKIP_SSL=0
SKIP_MAPS=0
for arg in "$@"; do
    case "$arg" in
        --dev | --prod) MODE="$arg" ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --email=*) EMAIL="${arg#*=}" ;;
        # Faqat sinov serveri uchun (domen yo'q): sertifikatsiz — brauzer mikrofoni ishlamaydi
        --skip-ssl) SKIP_SSL=1 ;;
        # Xarita/OSRM/hududlar keyinroq tayyorlanadi (deploy/osm-prepare.sh va boshqalar)
        --skip-maps) SKIP_MAPS=1 ;;
        *) echo "Noma'lum parametr: $arg" >&2; exit 1 ;;
    esac
done

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ "$MODE" == "--prod" ]]; then
    [[ -n "$DOMAIN" && -n "$EMAIL" ]] || { echo "--prod uchun --domain= va --email= kerak" >&2; exit 1; }
    APP_USER="${APP_USER:-ibtaxi}"
else
    APP_USER="${APP_USER:-ubuntu}"
fi
DB_NAME="${DB_NAME:-ibtaxi}"
DB_USER="${DB_USER:-ibtaxi}"
# Mavjud .env dagi parol saqlanadi; prod'da yangi tasodifiy parol
DB_PASS="${DB_PASS:-$(grep -s '^DB_PASSWORD=' "${APP_DIR}/.env" | cut -d= -f2 || true)}"
if [[ -z "$DB_PASS" ]]; then
    if [[ "$MODE" == "--prod" ]]; then DB_PASS="$(openssl rand -hex 16)"; else DB_PASS="ibtaxi"; fi
fi
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
if [[ "$MODE" == "--prod" ]]; then
    apt install -y -q certbot python3-certbot-nginx ufw fail2ban
    id -u "${APP_USER}" >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash "${APP_USER}"
fi

# OSRM ma'lumotini tayyorlash va build vaqtida xotira yetishi uchun (4 GB serverda OOM bo'ladi)
if [[ ! -f /swapfile ]] && (( $(free -m | awk '/^Mem:/{print $2}') + $(free -m | awk '/^Swap:/{print $2}') < 8000 )); then
    log "Swap (4 GB)"
    fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

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
sudo -u postgres psql -qc "ALTER ROLE ${DB_USER} PASSWORD '${DB_PASS}'"
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

if [[ "$MODE" == "--prod" ]]; then
    # php-fpm ilova foydalanuvchisi nomidan; nginx (www-data) faqat public/ ni o'qiydi
    sed -i "s/^user = .*/user = ${APP_USER}/; s/^group = .*/group = ${APP_USER}/" \
        "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    usermod -aG "${APP_USER}" www-data
    chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}"
    chmod 750 "${APP_DIR}"

    log "Prod: .env"
    ENV_FILE="${APP_DIR}/.env"
    [[ -f "$ENV_FILE" ]] || cp "${APP_DIR}/.env.example" "$ENV_FILE"
    set_env() {
        if grep -q "^$1=" "$ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"; else echo "$1=$2" >> "$ENV_FILE"; fi
    }
    set_env_once() { grep -q "^$1=.\+" "$ENV_FILE" || set_env "$1" "$2"; }
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "$([[ "$SKIP_SSL" == 1 ]] && echo http || echo https)://${DOMAIN}"
    set_env DB_HOST 127.0.0.1
    set_env DB_DATABASE "${DB_NAME}"
    set_env DB_USERNAME "${DB_USER}"
    set_env DB_PASSWORD "${DB_PASS}"
    set_env SESSION_SECURE_COOKIE "$([[ "$SKIP_SSL" == 1 ]] && echo false || echo true)"
    set_env_once REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
    set_env_once REVERB_APP_KEY "$(openssl rand -hex 10)"
    set_env_once REVERB_APP_SECRET "$(openssl rand -hex 10)"
    # Server → Reverb ichki ulanish; brauzer va ilova esa https://<domen>/app orqali
    set_env REVERB_HOST 127.0.0.1
    set_env REVERB_PORT 8080
    set_env REVERB_SCHEME http
    chown "${APP_USER}:${APP_USER}" "$ENV_FILE"
    chmod 640 "$ENV_FILE"
    PUBLIC_IP="${PUBLIC_IP:-$(curl -4 -fs --max-time 5 https://api.ipify.org || hostname -I | awk '{print $1}')}"
fi

# APK yuklash uchun PHP limitlari
for ini in "/etc/php/${PHP_VERSION}/fpm/php.ini" "/etc/php/${PHP_VERSION}/cli/php.ini"; do
    sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 200M/; s/^post_max_size = .*/post_max_size = 450M/' "$ini"
done

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
chown asterisk:asterisk /var/spool/asterisk/recording
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
    # Haydovchi ilovasi APK'si (har ABI ~80 MB) panel orqali yuklanadi
    client_max_body_size 450M;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        # Vite preload "Link" sarlavhasi katta bo'lishi mumkin (aks holda 502 "too big header")
        fastcgi_buffer_size 64k;
        fastcgi_buffers 16 32k;
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

if [[ "$MODE" == "--prod" ]]; then
    log "Prod: ilova (composer, frontend, migratsiyalar)"
    as_app() { sudo -u "${APP_USER}" -H bash -c "cd '${APP_DIR}' && $*"; }
    as_app "composer install --no-dev --optimize-autoloader --no-interaction"
    grep -q '^APP_KEY=base64' "${APP_DIR}/.env" || as_app "php artisan key:generate --force"
    as_app "npm ci --no-audit --no-fund && npm run build"
    as_app "php artisan migrate --force && php artisan db:seed --class=RolesSeeder --force"
    as_app "php artisan storage:link >/dev/null 2>&1 || true; php artisan optimize; php artisan telephony:sync"

    if [[ "$SKIP_MAPS" == 0 ]]; then
        log "Prod: xarita, marshrutlash (OSRM), hududlar va mo'ljallar (OSM)"
        [[ -f "${APP_DIR}/storage/app/osm/boundaries.geojsonseq" ]] || bash "${APP_DIR}/deploy/osm-prepare.sh"
        [[ -f "${APP_DIR}/public/map/region.pmtiles" ]] || bash "${APP_DIR}/deploy/map-prepare.sh"
        systemctl is-active --quiet ibtaxi-osrm || bash "${APP_DIR}/deploy/osrm-prepare.sh"
        chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}/storage/app/osm" "${APP_DIR}/public/map"
        as_app "php artisan regions:import && php artisan landmarks:import"
    fi

    log "Prod: HTTPS (Let's Encrypt)"
    sed -i "s/server_name _;/server_name ${DOMAIN};/" /etc/nginx/sites-available/ibtaxi
    nginx -t && systemctl reload nginx
    if [[ "$SKIP_SSL" == 1 ]]; then
        echo "OGOHLANTIRISH: --skip-ssl — sertifikat o'rnatilmadi (faqat sinov uchun)"
    else
        certbot --nginx -d "${DOMAIN}" -m "${EMAIL}" --agree-tos -n --redirect --keep-until-expiring
    fi

    log "Prod: xavfsizlik devori (ufw) va fail2ban"
    ufw allow OpenSSH
    ufw allow 80,443/tcp
    # SIP (trunk) va ovoz (RTP)
    ufw allow 5060/udp
    ufw allow 10000:20000/udp
    ufw --force enable
    cat > /etc/fail2ban/jail.d/ibtaxi.conf <<'JAIL'
[sshd]
enabled = true

[asterisk]
enabled = true
logpath = /var/log/asterisk/messages
maxretry = 5
bantime = 3600
JAIL
    systemctl enable --now fail2ban
    systemctl restart fail2ban
fi

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
if [[ "$MODE" == "--prod" ]]; then
    echo "Panel: https://${DOMAIN}  — birinchi kirishda sozlash ustasi ochiladi"
    echo "Zaxira: har kuni 02:30 (storage/app/private/backups), yangilash: php artisan ibtaxi:update"
    echo "SIP provayderga server IP: ${PUBLIC_IP}"
else
    echo "PostgreSQL: ${DB_NAME} / ${DB_USER}  (test bazasi: ${DB_NAME}_test)"
    echo "Panel: http://$(hostname -I | awk '{print $1}')"
fi
