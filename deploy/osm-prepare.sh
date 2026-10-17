#!/usr/bin/env bash
# OSM ma'lumotlarini tayyorlash: O'zbekiston PBF fayli → ma'muriy chegaralar (GeoJSON).
# Keyingi bosqichlarda shu fayldan OSRM marshrut ma'lumoti va xarita tile'lari ham quriladi.
#
#   ./osm-prepare.sh [chiqish_papkasi]      standart: <loyiha>/storage/app/osm
set -euo pipefail

OUT_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)/storage/app/osm}"
# Farg'ona vodiysi chegaralari Qirg'iziston va Tojikiston bilan kesishadi — ularning
# ma'lumotisiz Geofabrik ekstraktida vodiy viloyatlari chegarasi to'liq yopilmaydi
COUNTRIES="${COUNTRIES:-uzbekistan kyrgyzstan tajikistan}"
WORK_DIR="${OSM_WORK_DIR:-/var/lib/ibtaxi/osm}"

if ! command -v osmium >/dev/null; then
    sudo apt-get -o DPkg::Lock::Timeout=600 install -y -q osmium-tool
fi

sudo mkdir -p "$WORK_DIR" && sudo chown "$(id -u):$(id -g)" "$WORK_DIR"
mkdir -p "$OUT_DIR"

parts=()
for country in $COUNTRIES; do
    echo "==> PBF yuklanmoqda: $country"
    curl -fL --retry 3 -o "$WORK_DIR/$country.osm.pbf.tmp" "https://download.geofabrik.de/asia/$country-latest.osm.pbf"
    mv "$WORK_DIR/$country.osm.pbf.tmp" "$WORK_DIR/$country.osm.pbf"
    parts+=("$WORK_DIR/$country.osm.pbf")
done
echo "==> Birlashtirish"
osmium merge --overwrite -o "$WORK_DIR/region.osm.pbf" "${parts[@]}"

echo "==> Ma'muriy chegaralar (admin_level 4 va 6)"
osmium tags-filter --overwrite -o "$WORK_DIR/boundaries.osm.pbf" "$WORK_DIR/region.osm.pbf" \
    r/boundary=administrative
osmium export --overwrite -f geojsonseq -a type,id \
    -o "$WORK_DIR/boundaries.geojsonseq" "$WORK_DIR/boundaries.osm.pbf"
# Faqat poligonli chegaralar va kerakli darajalar — fayl kichikroq bo'ladi
grep -E '"admin_level":"(4|6)"' "$WORK_DIR/boundaries.geojsonseq" | grep -E '"type":"MultiPolygon"' > "$OUT_DIR/boundaries.geojsonseq"

echo "==> Tayyor: $OUT_DIR/boundaries.geojsonseq ($(wc -l < "$OUT_DIR/boundaries.geojsonseq") ta chegara)"

echo "==> Mo'ljallar (POI): bozor, maktab, kasalxona, masjid, do'kon va h.k."
osmium tags-filter --overwrite -o "$WORK_DIR/pois.osm.pbf" "$WORK_DIR/region.osm.pbf" \
    nwr/amenity=marketplace,school,college,university,hospital,clinic,place_of_worship,bus_station,fuel,restaurant,cafe,bank,police,post_office,theatre,cinema,kindergarten,pharmacy \
    nwr/shop=mall,supermarket,department_store \
    nwr/tourism=hotel,museum nwr/leisure=park,stadium nwr/railway=station nwr/building=train_station \
    n/place=neighbourhood,suburb,quarter,village,hamlet
osmium export --overwrite -f geojsonseq -a type,id -o "$WORK_DIR/pois.geojsonseq" "$WORK_DIR/pois.osm.pbf"
# Faqat nomi borlari
grep '"name' "$WORK_DIR/pois.geojsonseq" > "$OUT_DIR/pois.geojsonseq"
echo "==> Mo'ljallar: $(wc -l < "$OUT_DIR/pois.geojsonseq") ta"
