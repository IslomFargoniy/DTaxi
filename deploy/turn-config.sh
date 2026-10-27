#!/usr/bin/env bash
# Ixtiyoriy TURN server (coturn) — WebRTC ovozi server NAT ortida bo'lsa yoki operator/haydovchi
# tarmog'i UDP'ni yopsa (ofis, ba'zi mobil operatorlar) TCP/TLS orqali o'tadi. Asterisk'ga TURN kerak emas.
#
#   install.sh --prod --domain=... --turn       # o'rnatishda
#   sudo DOMAIN=taxi.firma.uz /var/www/dtaxi/deploy/turn-config.sh   # keyin alohida (qayta ishga tushirish xavfsiz)
#
# Natija: /etc/turnserver.conf (use-auth-secret), .env da TURN_URL va TURN_SECRET — panel va ilova
# har ulanishda vaqtinchalik login/parol oladi (App\Services\Telephony\IceServers).
# PUBLIC_IP, PRIVATE_IP — avtomatik; TURN_MIN_PORT/TURN_MAX_PORT — relay portlari (standart 49160-49760/udp).
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
APP_USER="${APP_USER:-dtaxi}"
ENV_FILE="${APP_DIR}/.env"
TURN_MIN_PORT="${TURN_MIN_PORT:-49160}"
TURN_MAX_PORT="${TURN_MAX_PORT:-49760}"
CERT_DIR=/etc/coturn/certs

if [[ $EUID -ne 0 ]]; then
    echo "Root sifatida ishga tushiring: sudo $0 $*" >&2
    exit 1
fi
[[ -f "$ENV_FILE" ]] || { echo "${ENV_FILE} topilmadi" >&2; exit 1; }

env_get() { grep -s "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
set_env() {
    if grep -q "^$1=" "$ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"; else echo "$1=$2" >> "$ENV_FILE"; fi
}

# Domen APP_URL dan (TLS sertifikati va realm shu nomga)
DOMAIN="${DOMAIN:-$(env_get APP_URL | sed -E 's#^https?://##; s#[:/].*$##')}"
[[ -n "$DOMAIN" ]] || { echo "DOMAIN topilmadi (DOMAIN=... yoki .env APP_URL)" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
apt-get -o DPkg::Lock::Timeout=600 install -y -q coturn

# Mavjud sir saqlanadi — aks holda ochiq turgan brauzerlarning paroli yaroqsiz bo'lib qolardi
SECRET="$(env_get TURN_SECRET)"
[[ -n "$SECRET" ]] || SECRET="$(openssl rand -hex 32)"

# Server NAT ortida bo'lsa (bulutdagi 10.x, ofis routeri) coturn relay manzilini ochiq IP sifatida e'lon qiladi
PRIVATE_IP="${PRIVATE_IP:-$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}')}"
PRIVATE_IP="${PRIVATE_IP:-$(hostname -I | awk '{print $1}')}"
PUBLIC_IP="${PUBLIC_IP:-$(curl -4 -fs --max-time 5 https://api.ipify.org || true)}"
PUBLIC_IP="${PUBLIC_IP:-$PRIVATE_IP}"
if [[ "$PUBLIC_IP" == "$PRIVATE_IP" ]]; then
    EXTERNAL_IP="$PUBLIC_IP"
else
    EXTERNAL_IP="${PUBLIC_IP}/${PRIVATE_IP}"
fi

# TLS (turns:5349) — Let's Encrypt sertifikati bo'lsa. coturn (turnserver foydalanuvchisi) /etc/letsencrypt ni
# o'qiy olmaydi: nusxa olinadi, certbot yangilaganda hook qayta ko'chiradi
LE_DIR="/etc/letsencrypt/live/${DOMAIN}"
TLS_CONF="no-tls
no-dtls"
URLS="turn:${DOMAIN}:3478?transport=udp,turn:${DOMAIN}:3478?transport=tcp"
if [[ -f "${LE_DIR}/fullchain.pem" ]]; then
    HOOK=/etc/letsencrypt/renewal-hooks/deploy/dtaxi-coturn.sh
    mkdir -p "$(dirname "$HOOK")"
    cat > "$HOOK" <<HOOK
#!/usr/bin/env bash
# install.sh --turn: yangilangan sertifikat coturn'ga (SIGUSR2 — qayta o'qiydi, relay'lar uzilmaydi)
set -e
install -d -m 750 -o root -g turnserver ${CERT_DIR}
install -m 640 -o root -g turnserver ${LE_DIR}/fullchain.pem ${CERT_DIR}/fullchain.pem
install -m 640 -o root -g turnserver ${LE_DIR}/privkey.pem ${CERT_DIR}/privkey.pem
systemctl kill -s USR2 coturn 2>/dev/null || true
HOOK
    chmod 755 "$HOOK"
    "$HOOK"
    TLS_CONF="tls-listening-port=5349
cert=${CERT_DIR}/fullchain.pem
pkey=${CERT_DIR}/privkey.pem
no-tlsv1
no-tlsv1_1
no-dtls"
    URLS="${URLS},turns:${DOMAIN}:5349?transport=tcp"
else
    echo "OGOHLANTIRISH: ${LE_DIR} da sertifikat yo'q — faqat turn:3478 (TLS'siz). Sertifikat olingach skriptni qayta ishga tushiring." >&2
fi

cat > /etc/turnserver.conf <<CONF
# deploy/turn-config.sh yozadi — qo'lda o'zgartirmang (qayta ishga tushirilsa ustidan yoziladi)
listening-port=3478
${TLS_CONF}
relay-ip=${PRIVATE_IP}
external-ip=${EXTERNAL_IP}
min-port=${TURN_MIN_PORT}
max-port=${TURN_MAX_PORT}
realm=${DOMAIN}
server-name=${DOMAIN}
fingerprint
# Vaqtinchalik login/parol (TURN REST API): Laravel shu sir bilan imzolaydi, bazada parol yo'q
use-auth-secret
static-auth-secret=${SECRET}
stale-nonce=600
# Ochiq relay sifatida suiiste'mol qilinmasin: yagona qonuniy tengdosh — shu serverdagi Asterisk.
# Ichki tarmoqlar (10.x, 192.168.x, 127.x, bulut metadata 169.254.x) va boshqa ochiq IP'lar ham yopiq
denied-peer-ip=0.0.0.0-255.255.255.255
denied-peer-ip=::-ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
allowed-peer-ip=${PRIVATE_IP}
allowed-peer-ip=${PUBLIC_IP}
no-multicast-peers
user-quota=12
total-quota=$(( TURN_MAX_PORT - TURN_MIN_PORT ))
no-cli
syslog
simple-log
CONF
chown root:turnserver /etc/turnserver.conf
chmod 640 /etc/turnserver.conf
# Eski paketlarda xizmat shu bayroqsiz ishga tushmaydi
[[ -f /etc/default/coturn ]] && sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn

if command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
    ufw allow 3478 comment 'dtaxi-turn' >/dev/null
    ufw allow 5349/tcp comment 'dtaxi-turn' >/dev/null
    ufw allow "${TURN_MIN_PORT}:${TURN_MAX_PORT}/udp" comment 'dtaxi-turn relay' >/dev/null
fi

# coturn faqat IP bo'yicha cheklaydi (allowed-peer-ip) — port bo'yicha emas: TURN login'i bor har kim shu serverning
# tashqaridan yopiq portlariga (5060/udp, 5432, 6379...) relay qila olardi. turnserver jarayoni chiqaradigan paketlar
# server IP'lariga faqat Asterisk RTP portlariga. Qoidalar loopback ruxsatidan (-o lo -j ACCEPT) oldin turishi shart
if [[ -f /etc/ufw/before.rules ]] && id -u turnserver >/dev/null 2>&1; then
    sed -i '/^# dtaxi-turn-begin$/,/^# dtaxi-turn-end$/d' /etc/ufw/before.rules
    BLOCK="# dtaxi-turn-begin"
    for ip in $(printf '%s\n' "$PRIVATE_IP" "$PUBLIC_IP" | sort -u); do
        BLOCK+=$'\n'"-A ufw-before-output -m owner --uid-owner turnserver -d ${ip} -p udp --dport 10000:20000 -j ACCEPT"
        BLOCK+=$'\n'"-A ufw-before-output -m owner --uid-owner turnserver -d ${ip} -j REJECT"
    done
    BLOCK+=$'\n'"# dtaxi-turn-end"
    awk -v block="$BLOCK" '!done && /^-A ufw-before-output/ { print block; done=1 } { print }' /etc/ufw/before.rules > /etc/ufw/before.rules.dtaxi
    mv /etc/ufw/before.rules.dtaxi /etc/ufw/before.rules
    ufw status | grep -q '^Status: active' && ufw reload >/dev/null
fi

systemctl enable coturn >/dev/null 2>&1
systemctl restart coturn

set_env TURN_URL "$URLS"
set_env TURN_SECRET "$SECRET"
chown "${APP_USER}:${APP_USER}" "$ENV_FILE" 2>/dev/null || true
# Konfiguratsiya keshi (optimize) yangi qiymatlarni o'qisin
if [[ -f "${APP_DIR}/bootstrap/cache/config.php" ]]; then
    sudo -u "${APP_USER}" -H bash -c "cd '${APP_DIR}' && php artisan config:cache" >/dev/null
fi
echo "TURN tayyor: ${URLS} (external-ip=${EXTERNAL_IP}, relay ${TURN_MIN_PORT}-${TURN_MAX_PORT}/udp)"
