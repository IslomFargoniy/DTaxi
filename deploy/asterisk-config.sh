#!/usr/bin/env bash
# deploy/asterisk/*.conf shablonlari → /etc/asterisk va /etc/odbc.ini, so'ng Asterisk qayta yuklanadi.
# install.sh chaqiradi; yangilanishda shablonlar o'zgarsa dtaxi:update shu buyruqni ko'rsatadi:
#   sudo /var/www/dtaxi/deploy/asterisk-config.sh            # core reload (qo'ng'iroqlar uzilmaydi)
#   sudo /var/www/dtaxi/deploy/asterisk-config.sh --restart  # to'liq qayta ishga tushirish
# Baza va ARI parollari .env dan; PUBLIC_IP, LOCAL_NET, STUN — muhit o'zgaruvchisi yoki oldingi
# o'rnatishda saqlangan /etc/dtaxi/asterisk.env dan.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATE=/etc/dtaxi/asterisk.env
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

# sed emas: parolda & | \\ bo'lsa sed almashtirishi buziladi. Bash'da qo'shtirnoqli almashtirish matni aynan qo'yiladi
render() {
    local content key
    content="$(cat "$1"; printf x)"
    content="${content%x}"
    for key in DB_NAME DB_USER DB_PASS ARI_PASSWORD PUBLIC_IP LOCAL_NET STUN; do
        content="${content//"{{${key}}}"/"${!key}"}"
    done
    printf '%s' "$content"
}

# Asterisk bazaga o'z roli bilan — faqat ps_* jadvallari (Realtime endpoint/auth/aor...). Ilova roli bilan Asterisk
# buzilsa butun baza (foydalanuvchilar, integratsiya kalitlari) ochiq bo'lardi. Har ishga tushishda yangi ps_* jadvallar
# ham qo'shiladi (toza o'rnatishda migratsiyadan oldin hali yo'q — install.sh migratsiyadan keyin yana chaqiradi)
if command -v psql >/dev/null && sudo -u postgres true 2>/dev/null; then
    AST_DB_USER="${DB_NAME}_asterisk"
    AST_PASS_FILE=/etc/dtaxi/asterisk-db.pass
    mkdir -p /etc/dtaxi
    [[ -s "$AST_PASS_FILE" ]] || (umask 077 && openssl rand -hex 16 > "$AST_PASS_FILE")
    AST_DB_PASS="$(cat "$AST_PASS_FILE")"
    pg() { (cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d "$DB_NAME" "$@"); }
    pg <<SQL
DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${AST_DB_USER}') THEN CREATE ROLE "${AST_DB_USER}" LOGIN; END IF; END \$\$;
ALTER ROLE "${AST_DB_USER}" PASSWORD '${AST_DB_PASS}';
GRANT CONNECT ON DATABASE "${DB_NAME}" TO "${AST_DB_USER}";
GRANT USAGE ON SCHEMA public TO "${AST_DB_USER}";
SQL
    for table in $(pg -Atc "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ps\\_%'"); do
        pg -c "GRANT SELECT, INSERT, UPDATE, DELETE ON public.\"${table}\" TO \"${AST_DB_USER}\""
    done
    for seq in $(pg -Atc "SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename LIKE 'ps\\_%'"); do
        pg -c "GRANT USAGE, SELECT ON SEQUENCE public.\"${seq}\" TO \"${AST_DB_USER}\""
    done
    DB_USER="$AST_DB_USER"
    DB_PASS="$AST_DB_PASS"
fi

# Parolli fayllar yozilish paytida ham boshqalarga ochiq bo'lmasin
umask 027
for f in "${APP_DIR}"/deploy/asterisk/*.conf; do
    render "$f" > "/etc/asterisk/$(basename "$f")"
done
render "${APP_DIR}/deploy/asterisk/odbc.ini" > /etc/odbc.ini
chown -R asterisk:asterisk /etc/asterisk
# ARI va ODBC parollari faqat Asterisk uchun (o'qiydi, o'zgartira olmaydi)
chown root:asterisk /etc/odbc.ini /etc/asterisk/ari.conf /etc/asterisk/res_odbc.conf
chmod 640 /etc/odbc.ini /etc/asterisk/ari.conf /etc/asterisk/res_odbc.conf

mkdir -p "$(dirname "$STATE")"
printf 'PUBLIC_IP=%s\nLOCAL_NET=%s\nSTUN=%s\n' "$PUBLIC_IP" "$LOCAL_NET" "$STUN" > "$STATE"
chmod 600 "$STATE"

if [[ "$RESTART" == 1 ]] || ! systemctl is-active --quiet asterisk; then
    systemctl restart asterisk
else
    asterisk -rx 'core reload' >/dev/null
fi
echo "Asterisk sozlamalari yangilandi (PUBLIC_IP=${PUBLIC_IP}, LOCAL_NET=${LOCAL_NET})"
