#!/usr/bin/env bash
# Xarita to'plamidan tiklash (php artisan ibtaxi:map-export yasagan *_map.tar):
# tile'lar va shriftlar, OSM eksportlari va OSRM marshrut ma'lumoti — internetdan qayta yasalmaydi.
#
#   sudo ./deploy/map-restore.sh /path/to/2026-10-21_030000_map.tar
#   (install.sh --map-bundle=... buni o'zi chaqiradi)
set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Foydalanish: sudo ./deploy/map-restore.sh <fayl>_map.tar" >&2
    exit 1
fi
BUNDLE="$1"
APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
APP_USER="${APP_USER:-ibtaxi}"
OSRM_DIR="${OSRM_DIR:-/var/lib/ibtaxi/osrm}"

[[ -f "$BUNDLE" ]] || { echo "Fayl topilmadi: $BUNDLE" >&2; exit 1; }
MEMBERS=$(tar -tf "$BUNDLE")
grep -q '^public/map/region.pmtiles$' <<<"$MEMBERS" || { echo "Bu xarita to'plami emas: $BUNDLE" >&2; exit 1; }

echo "==> Xarita tile'lari va shriftlar"
rm -rf "$APP_DIR/public/map"
tar -xf "$BUNDLE" -C "$APP_DIR" public/map

if grep -q '^storage/app/osm/' <<<"$MEMBERS"; then
    echo "==> OSM eksportlari (hududlar, mo'ljallar, ko'chalar, manzillar)"
    rm -rf "$APP_DIR/storage/app/osm"
    tar -xf "$BUNDLE" -C "$APP_DIR" storage/app/osm
fi
chown -R "$APP_USER:$APP_USER" "$APP_DIR/public/map" "$APP_DIR/storage/app/osm" 2>/dev/null || true

if grep -q '^osrm/' <<<"$MEMBERS"; then
    echo "==> OSRM marshrut ma'lumoti: $OSRM_DIR"
    mkdir -p "$(dirname "$OSRM_DIR")"
    rm -rf "$OSRM_DIR"
    tar -xf "$BUNDLE" -C "$(dirname "$OSRM_DIR")" osrm
    [[ "$(basename "$OSRM_DIR")" == osrm ]] || mv "$(dirname "$OSRM_DIR")/osrm" "$OSRM_DIR"
    # Ma'lumot tayyor — faqat Docker va systemd xizmati sozlanadi
    OSRM_DIR="$OSRM_DIR" SKIP_BUILD=1 bash "$APP_DIR/deploy/osrm-prepare.sh"
fi

echo "==> Tayyor. Hududlar va mo'ljallar bazada (zaxiradan tiklangan bo'lsa) yoki: php artisan regions:import && php artisan landmarks:import"
