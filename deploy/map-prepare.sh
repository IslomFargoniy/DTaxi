#!/usr/bin/env bash
# Xarita: Protomaps planet build'idan tanlangan hudud tile'larini kesib olish (`.pmtiles`) va
# shrift/belgilarni o'z serverga yuklash — xarita uchun tashqi API va oylik to'lov yo'q (qaror #2).
#
#   ./map-prepare.sh                         standart hudud — Farg'ona vodiysi
#   BBOX=55.9,37.1,73.2,45.6 ./map-prepare.sh   butun O'zbekiston
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${OUT_DIR:-$APP_DIR/public/map}"
BBOX="${BBOX:-70.4,39.9,73.3,41.6}"
MAXZOOM="${MAXZOOM:-15}"
PMTILES_VERSION="1.31.2"

mkdir -p "$OUT_DIR"

if ! command -v pmtiles >/dev/null; then
    arch="$(uname -m)"; [[ "$arch" == "aarch64" ]] && arch="arm64"
    tmp="$(mktemp -d)"
    curl -fsSL "https://github.com/protomaps/go-pmtiles/releases/download/v${PMTILES_VERSION}/go-pmtiles_${PMTILES_VERSION}_Linux_${arch}.tar.gz" | tar -xz -C "$tmp"
    sudo install "$tmp/pmtiles" /usr/local/bin/pmtiles
fi

BUILD="$(curl -fsSL https://build-metadata.protomaps.dev/builds.json | python3 -c 'import sys,json; print(json.load(sys.stdin)[-1]["key"])')"
echo "==> Tile'lar: $BUILD, hudud $BBOX, zoom ≤ $MAXZOOM"
pmtiles extract "https://build.protomaps.com/$BUILD" "$OUT_DIR/region.pmtiles.tmp" --bbox="$BBOX" --maxzoom="$MAXZOOM"
mv "$OUT_DIR/region.pmtiles.tmp" "$OUT_DIR/region.pmtiles"

echo "==> Shriftlar va belgilar"
tmp="$(mktemp -d)"
curl -fsSL https://github.com/protomaps/basemaps-assets/archive/refs/heads/main.tar.gz | tar -xz -C "$tmp"
rm -rf "$OUT_DIR/fonts" "$OUT_DIR/sprites"
cp -r "$tmp"/basemaps-assets-main/fonts "$OUT_DIR/fonts"
cp -r "$tmp"/basemaps-assets-main/sprites "$OUT_DIR/sprites"

echo "==> Tayyor: $(du -sh "$OUT_DIR/region.pmtiles" | cut -f1) — $OUT_DIR"
