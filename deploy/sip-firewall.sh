#!/usr/bin/env bash
# SIP (5060/udp) faqat firma trunk'lari IP'lariga ochiq — internetdagi SIP skanerlar parol tanlay olmaydi.
# Operator va haydovchilar WSS (443) orqali ulanadi, ularga 5060 kerak emas.
# install.sh --prod cron'ga qo'yadi (har 5 daqiqada); trunk panelda o'zgarsa qoidalar o'zi yangilanadi.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/ibtaxi}"
APP_USER="${APP_USER:-www-data}"
STATE=/var/lib/ibtaxi/sip-ips

mkdir -p "$(dirname "$STATE")"
WANT=$(sudo -u "$APP_USER" php "$APP_DIR/artisan" telephony:trunk-ips 2>/dev/null | grep -E '^[0-9a-fA-F:.]+$' || true)
HAVE=$(cat "$STATE" 2>/dev/null || true)

[[ "$WANT" == "$HAVE" ]] && exit 0

# Eski qoidalar (izoh bo'yicha) o'chiriladi, yangilari qo'shiladi
while read -r num; do
    ufw --force delete "$num" >/dev/null
done < <(ufw status numbered | grep 'ibtaxi-sip' | sed -E 's/^\[ *([0-9]+)\].*/\1/' | sort -rn)

for ip in $WANT; do
    ufw allow from "$ip" to any port 5060 proto udp comment 'ibtaxi-sip' >/dev/null
done

echo "$WANT" > "$STATE"
logger -t ibtaxi-sip "SIP trunk IP'lari yangilandi: $(echo $WANT | tr '\n' ' ')"
