#!/usr/bin/env bash
# Ochiq joylar (POI) ma'lumoti: OSM'dan tashqari nomli joylar — mo'ljallar bazasini boyitish (qaror #60).
# Faqat tijoriy foydalanishga ruxsat beruvchi ochiq litsenziyali manbalar (Yandex/Google/2GIS yo'q):
#   overture — Overture Maps "places" (CDLA-Permissive-2.0; ichidagi Foursquare yozuvlari Apache-2.0,
#              AllThePlaces — CC0), ochiq S3'dagi GeoParquet'dan faqat BBOX qismi DuckDB bilan olinadi
#   wikidata — Wikidata (CC0), rasmiy SPARQL endpoint orqali faqat taksi uchun foydali turlar
#   geonames — GeoNames (CC-BY 4.0, atributsiya "GeoNames" majburiy), mamlakat fayllari (UZ, KG, TJ):
#              qishloq/mahalla nomlari va foydali binolar (shifoxona, maktab, vokzal...), qaror #64
# Chiqish: <papka>/places-<manba>.geojsonseq (+ overture uchun litsenziya NOTICE fayli).
# Qayta ishga tushirish xavfsiz: fayllar vaqtinchalik nomga yozilib, oxirida almashtiriladi.
#
#   ./places-prepare.sh [chiqish_papkasi]          standart: <loyiha>/storage/app/osm, butun O'zbekiston (qaror #67)
#   BBOX=70.4,39.9,73.3,41.6 ./places-prepare.sh    faqat Farg'ona vodiysi
#   SOURCES=wikidata ./places-prepare.sh            faqat bitta manba
#   OVERTURE_MIN_CONFIDENCE=0.5                     Overture ishonch chegarasi (pastlari — ko'pincha axlat)
#   GEONAMES_COUNTRIES="UZ KG TJ"                   GeoNames mamlakat fayllari (BBOX bo'yicha kesiladi)
# Import: php artisan landmarks:import --source=overture --file=<papka>/places-overture.geojsonseq
set -euo pipefail

OUT_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)/storage/app/osm}"
BBOX="${BBOX:-55.9,37.1,73.2,45.6}"
SOURCES="${SOURCES:-overture wikidata geonames}"
GEONAMES_COUNTRIES="${GEONAMES_COUNTRIES:-UZ KG TJ}"
MIN_CONFIDENCE="${OVERTURE_MIN_CONFIDENCE:-0.5}"
WORK_DIR="${PLACES_WORK_DIR:-${OSM_WORK_DIR:-/var/lib/dtaxi/osm}/places}"
DUCKDB_VERSION="1.4.4"

IFS=, read -r X1 Y1 X2 Y2 <<<"$BBOX"
mkdir -p "$WORK_DIR" 2>/dev/null || { sudo mkdir -p "$WORK_DIR" && sudo chown "$(id -u):$(id -g)" "$WORK_DIR"; }
mkdir -p "$OUT_DIR"

DUCKDB="$(command -v duckdb || true)"
if [[ -z "$DUCKDB" ]]; then
    DUCKDB="$WORK_DIR/duckdb"
    if [[ ! -x "$DUCKDB" ]]; then
        case "$(uname -s)-$(uname -m)" in
            Linux-x86_64) asset=linux-amd64 ;;
            Linux-aarch64 | Linux-arm64) asset=linux-arm64 ;;
            Darwin-*) asset=osx-universal ;;
            *) echo "DuckDB uchun platforma qo'llanmaydi: $(uname -sm)" >&2; exit 1 ;;
        esac
        echo "==> DuckDB ${DUCKDB_VERSION} yuklanmoqda"
        curl -fsSL --retry 3 -o "$WORK_DIR/duckdb.zip" \
            "https://github.com/duckdb/duckdb/releases/download/v${DUCKDB_VERSION}/duckdb_cli-${asset}.zip"
        # unzip toza Ubuntu'da bo'lmasligi mumkin — python3 bor
        python3 -m zipfile -e "$WORK_DIR/duckdb.zip" "$WORK_DIR/"
        chmod +x "$DUCKDB"
    fi
fi

overture() {
    local release
    release="$(curl -fsSL https://stac.overturemaps.org/catalog.json | python3 -c 'import sys,json; print(json.load(sys.stdin)["latest"])')"
    echo "==> Overture places ${release}, hudud ${BBOX} (butun dunyo fayllaridan faqat hudud — bir necha daqiqa)"
    # Avval hudud qismi lokal parquet'ga — keyingi so'rovlar internetsiz; shu reliz bor bo'lsa qayta yuklanmaydi
    local parquet="$WORK_DIR/overture-${release}-${BBOX}.parquet"
    [[ -f "$parquet" ]] || "$DUCKDB" -c "
        INSTALL httpfs; LOAD httpfs; SET s3_region='us-west-2';
        COPY (
            SELECT id, names, basic_category, taxonomy, confidence, operating_status, sources, bbox
            FROM read_parquet('s3://overturemaps-us-west-2/release/${release}/theme=places/type=place/*', hive_partitioning=1)
            WHERE bbox.xmin BETWEEN ${X1} AND ${X2} AND bbox.ymin BETWEEN ${Y1} AND ${Y2}
        ) TO '$parquet.tmp' (FORMAT parquet);"
    [[ -f "$parquet" ]] || mv "$parquet.tmp" "$parquet"

    "$DUCKDB" -c "
        COPY (
            SELECT 'Feature' AS type,
                {'type': 'Point', 'coordinates': [bbox.xmin, bbox.ymin]} AS geometry,
                {'id': id, 'name': names.\"primary\", 'names': names.common,
                 'categories': list_filter([taxonomy.\"primary\", basic_category], c -> c IS NOT NULL)} AS properties
            FROM '$parquet'
            WHERE names.\"primary\" IS NOT NULL AND confidence >= ${MIN_CONFIDENCE}
              AND coalesce(operating_status, 'open') = 'open'
        ) TO '$OUT_DIR/places-overture.geojsonseq.tmp' (FORMAT json);"
    mv "$OUT_DIR/places-overture.geojsonseq.tmp" "$OUT_DIR/places-overture.geojsonseq"

    # Litsenziya talabi (Apache-2.0 NOTICE, CDLA atributsiyasi) — fayl ma'lumot bilan birga tarqatiladi
    {
        echo "Overture Maps Foundation — places theme, release ${release} (https://overturemaps.org)"
        echo "Attribution: https://docs.overturemaps.org/attribution/"
        echo
        echo "Sources and licences of the records in this extract:"
        "$DUCKDB" -noheader -list -c "
            SELECT '  ' || dataset || ' — ' || license || ' (' || n || ')' FROM (
                SELECT s.dataset, s.license, count(*) AS n
                FROM (SELECT unnest(sources) AS s FROM '$parquet') GROUP BY ALL
            ) ORDER BY n DESC;"
        echo
        echo "Foursquare: Copyright 2024 Foursquare Labs, Inc. All rights reserved."
        echo "Foursquare data was transformed to the Overture schema. Licensed under the Apache License 2.0"
        echo "(https://www.apache.org/licenses/LICENSE-2.0); NOTICE: https://opensource.foursquare.com/places-notice-txt/"
        echo "CDLA-Permissive-2.0: https://cdla.dev/permissive-2-0/ — CC0-1.0: https://creativecommons.org/publicdomain/zero/1.0/"
    } > "$OUT_DIR/places-overture.NOTICE.txt"
    echo "==> Overture: $(wc -l < "$OUT_DIR/places-overture.geojsonseq") ta joy (ishonch ≥ ${MIN_CONFIDENCE})"
}

wikidata() {
    echo "==> Wikidata (CC0), hudud ${BBOX}"
    # Faqat taksi mo'ljali bo'ladigan turlar; qishloq/daryo/tog'lar OSM'da bor
    local query
    query="SELECT ?item ?cat ?loc ?uz ?ru ?en WHERE {
      SERVICE wikibase:box {
        ?item wdt:P625 ?loc .
        bd:serviceParam wikibase:cornerSouthWest \"Point(${X1} ${Y1})\"^^geo:wktLiteral .
        bd:serviceParam wikibase:cornerNorthEast \"Point(${X2} ${Y2})\"^^geo:wktLiteral .
      }
      VALUES (?type ?cat) {
        (wd:Q32815 \"muslim_place_of_worship\") (wd:Q16970 \"church\") (wd:Q162875 \"mausoleum\")
        (wd:Q132834 \"madrasa\") (wd:Q4989906 \"monument\") (wd:Q2319498 \"architectural_landmark\")
        (wd:Q41176 \"building\") (wd:Q27686 \"hotel\") (wd:Q483110 \"stadium\") (wd:Q1248784 \"airport\")
        (wd:Q94993988 \"airport\") (wd:Q3918 \"college_university\") (wd:Q38723 \"college_university\")
        (wd:Q33506 \"museum\") (wd:Q22698 \"park\") (wd:Q16917 \"hospital\") (wd:Q3914 \"school\")
        (wd:Q132510 \"market\") (wd:Q330284 \"market\") (wd:Q24354 \"theatre_venue\")
        (wd:Q55488 \"train_station\") (wd:Q494829 \"bus_station\") (wd:Q757292 \"border_checkpoint\")
        (wd:Q11315 \"shopping_mall\") (wd:Q41253 \"movie_theater\") (wd:Q570116 \"tourist_attraction\")
      }
      ?item wdt:P31 ?type .
      OPTIONAL { ?item rdfs:label ?uz FILTER(LANG(?uz) = \"uz\") }
      OPTIONAL { ?item rdfs:label ?ru FILTER(LANG(?ru) = \"ru\") }
      OPTIONAL { ?item rdfs:label ?en FILTER(LANG(?en) = \"en\") }
    }"
    curl -fsSL --retry 3 -G https://query.wikidata.org/sparql \
        -H 'Accept: text/csv' -H 'User-Agent: Dtaxi-places-prepare/1.0 (taxi dispatch; open data import)' \
        --data-urlencode "query=${query}" -o "$WORK_DIR/wikidata.csv"

    # Bitta obyekt bir necha tur/koordinata bilan takrorlanadi — birinchisi yetarli
    "$DUCKDB" -c "
        COPY (
            SELECT 'Feature' AS type,
                {'type': 'Point', 'coordinates': [
                    regexp_extract(first(loc), 'Point\(([-0-9.eE]+) ([-0-9.eE]+)\)', 1)::DOUBLE,
                    regexp_extract(first(loc), 'Point\(([-0-9.eE]+) ([-0-9.eE]+)\)', 2)::DOUBLE]} AS geometry,
                {'id': replace(item, 'http://www.wikidata.org/entity/', ''),
                 'name': coalesce(first(uz), first(ru), first(en)),
                 'names': {'uz': first(uz), 'ru': first(ru), 'en': first(en)},
                 'categories': [first(cat)]} AS properties
            FROM read_csv('$WORK_DIR/wikidata.csv', header = true, all_varchar = true)
            GROUP BY item
            HAVING coalesce(first(uz), first(ru), first(en)) IS NOT NULL
        ) TO '$OUT_DIR/places-wikidata.geojsonseq.tmp' (FORMAT json);"
    mv "$OUT_DIR/places-wikidata.geojsonseq.tmp" "$OUT_DIR/places-wikidata.geojsonseq"
    echo "==> Wikidata: $(wc -l < "$OUT_DIR/places-wikidata.geojsonseq") ta joy"
}

geonames() {
    echo "==> GeoNames (CC-BY 4.0): ${GEONAMES_COUNTRIES}, hudud ${BBOX}"
    local country files=() alt_files=()
    mkdir -p "$WORK_DIR/geonames"
    for country in $GEONAMES_COUNTRIES; do
        # Asosiy fayl va shu mamlakat obyektlarining boshqa tillardagi nomlari (rasmiy dump, kuniga yangilanadi)
        curl -fsSL --retry 3 -A 'Dtaxi-places-prepare/1.0' -o "$WORK_DIR/geonames/${country}.zip" \
            "https://download.geonames.org/export/dump/${country}.zip"
        curl -fsSL --retry 3 -A 'Dtaxi-places-prepare/1.0' -o "$WORK_DIR/geonames/${country}-alt.zip" \
            "https://download.geonames.org/export/dump/alternatenames/${country}.zip"
        python3 -m zipfile -e "$WORK_DIR/geonames/${country}.zip" "$WORK_DIR/geonames/"
        mkdir -p "$WORK_DIR/geonames/alt"
        python3 -m zipfile -e "$WORK_DIR/geonames/${country}-alt.zip" "$WORK_DIR/geonames/alt/"
        files+=("'$WORK_DIR/geonames/${country}.txt'")
        alt_files+=("'$WORK_DIR/geonames/alt/${country}.txt'")
    done
    local IFS=,

    # Faqat taksi mo'ljali bo'ladigan turlar: qishloq/mahalla (PPL, PPLX, PPLL) va binolar. Shahar/tuman
    # markazlari (PPLA*) — hududlar jadvalida; tog' yaylovlari, xarobalar, urochishe — kerak emas.
    # Turlar: https://www.geonames.org/export/codes.html
    "$DUCKDB" -c "
        CREATE TABLE g AS SELECT * FROM read_csv([${files[*]}], delim = '\t', header = false, quote = '', escape = '',
            columns = {'id': 'BIGINT', 'name': 'VARCHAR', 'ascii': 'VARCHAR', 'alternates': 'VARCHAR', 'lat': 'DOUBLE',
                'lng': 'DOUBLE', 'class': 'VARCHAR', 'code': 'VARCHAR', 'country': 'VARCHAR', 'cc2': 'VARCHAR',
                'a1': 'VARCHAR', 'a2': 'VARCHAR', 'a3': 'VARCHAR', 'a4': 'VARCHAR', 'population': 'BIGINT',
                'elevation': 'VARCHAR', 'dem': 'VARCHAR', 'timezone': 'VARCHAR', 'modified': 'VARCHAR'})
        WHERE lng BETWEEN ${X1} AND ${X2} AND lat BETWEEN ${Y1} AND ${Y2}
          AND code IN ('PPL', 'PPLX', 'PPLL', 'ADMF', 'AIRP', 'ATHF', 'BLDG', 'BUSTN', 'CH', 'MSQE', 'SHRN', 'TMB',
              'HSP', 'HSPC', 'HSPD', 'HTL', 'MKT', 'MALL', 'MUS', 'MNMT', 'PO', 'PRK', 'RSTN', 'SCH', 'SCHC', 'SCHM',
              'SCHT', 'UNIV', 'STDM', 'THTR', 'LIBR', 'RSRT', 'BANK');
        -- Tarixiy nomlar va havolalar emas; avval 'afzal' deb belgilangani
        CREATE TABLE a AS SELECT geonameid AS id, lang, first(name ORDER BY preferred DESC, alt_id) AS name
            FROM read_csv([${alt_files[*]}], delim = '\t', header = false, quote = '', escape = '',
                columns = {'alt_id': 'BIGINT', 'geonameid': 'BIGINT', 'lang': 'VARCHAR', 'name': 'VARCHAR',
                    'preferred': 'VARCHAR', 'short': 'VARCHAR', 'colloquial': 'VARCHAR', 'historic': 'VARCHAR',
                    'from': 'VARCHAR', 'to': 'VARCHAR'})
            WHERE lang IN ('uz', 'ru', 'en') AND coalesce(historic, '') <> '1' AND geonameid IN (SELECT id FROM g)
            GROUP BY ALL;
        COPY (
            SELECT 'Feature' AS type,
                {'type': 'Point', 'coordinates': [g.lng, g.lat]} AS geometry,
                {'id': g.id::VARCHAR, 'name': g.name,
                 'names': {'uz': uz.name, 'ru': ru.name, 'en': en.name},
                 'categories': [g.code]} AS properties
            FROM g
            LEFT JOIN a uz ON uz.id = g.id AND uz.lang = 'uz'
            LEFT JOIN a ru ON ru.id = g.id AND ru.lang = 'ru'
            LEFT JOIN a en ON en.id = g.id AND en.lang = 'en'
            ORDER BY g.id
        ) TO '$OUT_DIR/places-geonames.geojsonseq.tmp' (FORMAT json);"
    mv "$OUT_DIR/places-geonames.geojsonseq.tmp" "$OUT_DIR/places-geonames.geojsonseq"

    {
        echo "GeoNames geographical database (https://www.geonames.org), country dumps: ${GEONAMES_COUNTRIES}"
        echo "Downloaded $(date -u +%Y-%m-%d) from https://download.geonames.org/export/dump/"
        echo "Licence: Creative Commons Attribution 4.0 (https://creativecommons.org/licenses/by/4.0/)."
        echo "Attribution \"GeoNames\" is shown in the map attribution. Data is provided \"as is\"; it was"
        echo "filtered by area and feature type and merged with OpenStreetMap landmarks (duplicates skipped)."
    } > "$OUT_DIR/places-geonames.NOTICE.txt"
    echo "==> GeoNames: $(wc -l < "$OUT_DIR/places-geonames.geojsonseq") ta joy"
}

for source in $SOURCES; do
    case "$source" in
        overture) overture ;;
        wikidata) wikidata ;;
        geonames) geonames ;;
        *) echo "Noma'lum manba: $source" >&2; exit 1 ;;
    esac
done
echo "    Import: php artisan landmarks:import --source=<manba> --file=$OUT_DIR/places-<manba>.geojsonseq"
