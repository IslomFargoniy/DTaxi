#!/usr/bin/env bash
# Xarita to'plamidan tiklash (php artisan dtaxi:map-export yasagan *_map.tar):
# tile'lar va shriftlar, OSM eksportlari va OSRM marshrut ma'lumoti — internetdan qayta yasalmaydi;
# map.dump bo'lsa (qaror #65) — bazadagi hududlar, mo'ljallar va ko'cha nomlari ham (dtaxi:map-import, mavjudlari almashtiriladi).
# Baza qismi uchun migratsiyalar bajarilgan bo'lishi kerak (install.sh shu tartibda chaqiradi).
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
APP_USER="${APP_USER:-dtaxi}"
OSRM_DIR="${OSRM_DIR:-/var/lib/dtaxi/osrm}"

[[ -f "$BUNDLE" ]] || { echo "Fayl topilmadi: $BUNDLE" >&2; exit 1; }
MEMBERS=$(tar -tf "$BUNDLE")
grep -q '^public/map/region.pmtiles$' <<<"$MEMBERS" || { echo "Bu xarita to'plami emas: $BUNDLE" >&2; exit 1; }

echo "==> Xarita tile'lari va shriftlar"
rm -rf "$APP_DIR/public/map"
tar -xf "$BUNDLE" --no-same-owner -C "$APP_DIR" public/map

if grep -q '^storage/app/osm/' <<<"$MEMBERS"; then
    echo "==> OSM eksportlari (hududlar, mo'ljallar, ko'chalar, manzillar)"
    rm -rf "$APP_DIR/storage/app/osm"
    tar -xf "$BUNDLE" --no-same-owner -C "$APP_DIR" storage/app/osm
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

if grep -qx 'map.dump' <<<"$MEMBERS"; then
    echo "==> Bazadagi xarita: hududlar, mo'ljallar, ko'cha nomlari"
    TMP_DIR=$(mktemp -d)
    trap 'rm -rf "$TMP_DIR"' EXIT
    tar -xf "$BUNDLE" --no-same-owner -C "$TMP_DIR" map.dump
    chown -R "$APP_USER:$APP_USER" "$TMP_DIR"
    # Filial yoki mijoz manzili mavjud xaritaga bog'langan bo'lsa buyruq rad etadi (bu ishlab turgan server) —
    # o'rnatish shu yerda to'xtaydi; ataylab almashtirish: sudo -u $APP_USER php artisan dtaxi:map-import <fayl> --force
    sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && php artisan dtaxi:map-import --no-interaction '$TMP_DIR/map.dump'"
    echo "==> Tayyor. OSM importi (regions:import, landmarks:import) kerak emas — u o'chirilgan mo'ljallarni qaytaradi."
else
    echo "==> Tayyor. To'plamda bazadagi xarita yo'q (eski to'plam) — hududlar va mo'ljallar: php artisan regions:import && php artisan landmarks:import"
fi
