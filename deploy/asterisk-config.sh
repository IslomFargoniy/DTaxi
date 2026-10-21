#!/usr/bin/env bash
# deploy/asterisk/*.conf shablonlari → /etc/asterisk va /etc/odbc.ini, so'ng Asterisk qayta yuklanadi.
# install.sh chaqiradi; yangilanishda shablonlar o'zgarsa ibtaxi:update shu buyruqni ko'rsatadi:
#   sudo /var/www/ibtaxi/deploy/asterisk-config.sh            # core reload (qo'ng'iroqlar uzilmaydi)
#   sudo /var/www/ibtaxi/deploy/asterisk-config.sh --restart  # to'liq qayta ishga tushirish
# Baza va ARI parollari .env dan; PUBLIC_IP, LOCAL_NET, STUN — muhit o'zgaruvchisi yoki oldingi
# o'rnatishda saqlangan /etc/ibtaxi/asterisk.env dan.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATE=/etc/ibtaxi/asterisk.env
RESTART=0
[[ "${1:-}" == "--restart" ]] && RESTART=1

if [[ $EUID -ne 0 ]]; then
    echo "Root sifatida ishga tushiring: sudo $0 $*" >&2
    exit 1
fi

# Saqlangan qiymatlar; muhit o'zgaruvchisi berilgan bo'lsa — u ustun
if [[ -f "$STATE" ]]; then
    while IFS='=' read -r key value; do
        case "$key" in
            PUBLIC_IP | LOCAL_NET | STUN) [[ -n "${!key:-}" ]] || printf -v "$key" '%s' "$value" ;;
        esac
    done < "$STATE"
fi

env_get() { grep -s "^$1=" "${APP_DIR}/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

DB_NAME="${DB_NAME:-$(env_get DB_DATABASE)}"
DB_USER="${DB_USER:-$(env_get DB_USERNAME)}"
DB_PASS="${DB_PASS:-$(env_get DB_PASSWORD)}"
ARI_PASSWORD="${ARI_PASSWORD:-$(env_get ASTERISK_ARI_PASSWORD)}"
for var in DB_NAME DB_USER DB_PASS ARI_PASSWORD; do
    [[ -n "${!var}" ]] || { echo "${var} topilmadi (${APP_DIR}/.env)" >&2; exit 1; }
done

PUBLIC_IP="${PUBLIC_IP:-$(curl -4 -fs --max-time 5 https://api.ipify.org || hostname -I | awk '{print $1}')}"
# Server ichki tarmog'i — asosiy interfeys (default route) tarmog'idan; bulutda 10.x, ofisda 192.168.x bo'ladi
if [[ -z "${LOCAL_NET:-}" ]]; then
    iface="$(ip route show default 2>/dev/null | awk '{print $5; exit}')"
    LOCAL_NET="$([[ -n "$iface" ]] && ip -o -4 route show dev "$iface" scope link 2>/dev/null | awk '$1 ~ /\// {print $1; exit}' || true)"
    LOCAL_NET="${LOCAL_NET:-192.168.0.0/16}"
fi
STUN="${STUN:-stun.l.google.com:19302}"

render() {
    sed -e "s|{{DB_NAME}}|${DB_NAME}|g" -e "s|{{DB_USER}}|${DB_USER}|g" -e "s|{{DB_PASS}}|${DB_PASS}|g" \
        -e "s|{{ARI_PASSWORD}}|${ARI_PASSWORD}|g" -e "s|{{PUBLIC_IP}}|${PUBLIC_IP}|g" \
        -e "s|{{LOCAL_NET}}|${LOCAL_NET}|g" -e "s|{{STUN}}|${STUN}|g" "$1"
}

for f in "${APP_DIR}"/deploy/asterisk/*.conf; do
    render "$f" > "/etc/asterisk/$(basename "$f")"
done
render "${APP_DIR}/deploy/asterisk/odbc.ini" > /etc/odbc.ini
chown -R asterisk:asterisk /etc/asterisk
# ODBC paroli faqat Asterisk uchun
chown root:asterisk /etc/odbc.ini
chmod 640 /etc/odbc.ini

mkdir -p "$(dirname "$STATE")"
printf 'PUBLIC_IP=%s\nLOCAL_NET=%s\nSTUN=%s\n' "$PUBLIC_IP" "$LOCAL_NET" "$STUN" > "$STATE"
chmod 600 "$STATE"

if [[ "$RESTART" == 1 ]] || ! systemctl is-active --quiet asterisk; then
    systemctl restart asterisk
else
    asterisk -rx 'core reload' >/dev/null
fi
echo "Asterisk sozlamalari yangilandi (PUBLIC_IP=${PUBLIC_IP}, LOCAL_NET=${LOCAL_NET})"
