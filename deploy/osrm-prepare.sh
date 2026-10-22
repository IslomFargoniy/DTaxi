#!/usr/bin/env bash
# OSRM: yo'l masofasi va ETA uchun o'z serverimizdagi marshrut xizmati (reja §4).
# Manba — deploy/osm-prepare.sh yuklagan PBF; hudud BBOX bilan kesiladi (RAM kamroq).
#
#   ./osrm-prepare.sh                          standart — butun O'zbekiston (qaror #67)
#   BBOX=70.4,39.9,73.3,41.6 ./osrm-prepare.sh    faqat Farg'ona vodiysi (RAM kamroq)
#   OSRM_BIND=0.0.0.0 ./osrm-prepare.sh        (faqat lokal VM: Mac'dan ulanish uchun)
#   SKIP_BUILD=1 ./osrm-prepare.sh             ma'lumot tayyor (xarita to'plamidan) — faqat xizmat
set -euo pipefail

WORK_DIR="${OSM_WORK_DIR:-/var/lib/ibtaxi/osm}"
OSRM_DIR="${OSRM_DIR:-/var/lib/ibtaxi/osrm}"
BBOX="${BBOX:-55.9,37.1,73.2,45.6}"
OSRM_BIND="${OSRM_BIND:-127.0.0.1}"
IMAGE="${OSRM_IMAGE:-ghcr.io/project-osrm/osrm-backend:v6.0.0}"

SKIP_BUILD="${SKIP_BUILD:-0}"
if [[ "$SKIP_BUILD" == 1 ]] && ! compgen -G "$OSRM_DIR/area.osrm.*" >/dev/null; then
    echo "SKIP_BUILD=1, lekin $OSRM_DIR da OSRM ma'lumoti yo'q" >&2
    exit 1
fi
if [[ "$SKIP_BUILD" != 1 && ! -f "$WORK_DIR/region.osm.pbf" ]]; then
    echo "Avval deploy/osm-prepare.sh ni ishga tushiring" >&2
    exit 1
fi

if ! command -v docker >/dev/null; then
    sudo apt-get -o DPkg::Lock::Timeout=600 install -y -q docker.io
    sudo systemctl enable --now docker
fi

sudo docker pull -q "$IMAGE"
if [[ "$SKIP_BUILD" != 1 ]]; then
    sudo mkdir -p "$OSRM_DIR"
    echo "==> Hudud kesilmoqda: $BBOX"
    sudo osmium extract --overwrite -b "$BBOX" -o "$OSRM_DIR/area.osm.pbf" "$WORK_DIR/region.osm.pbf"

    echo "==> OSRM ma'lumoti qurilmoqda (avtomobil profili)"
    sudo docker run --rm -v "$OSRM_DIR:/data" "$IMAGE" osrm-extract -p /opt/car.lua /data/area.osm.pbf
    sudo docker run --rm -v "$OSRM_DIR:/data" "$IMAGE" osrm-partition /data/area.osrm
    sudo docker run --rm -v "$OSRM_DIR:/data" "$IMAGE" osrm-customize /data/area.osrm
fi
# osrm-extract fileIndex'ni 0700 yaratadi — ilova (ibtaxi:map-export) uni arxivlay olmasdi; ma'lumot maxfiy emas
sudo chmod -R a+rX "$OSRM_DIR"

echo "==> Xizmat (systemd): $OSRM_BIND:5000"
sudo tee /etc/systemd/system/ibtaxi-osrm.service >/dev/null <<UNIT
[Unit]
Description=Dtaxi OSRM
After=docker.service
Requires=docker.service

[Service]
Restart=always
ExecStartPre=-/usr/bin/docker rm -f ibtaxi-osrm
ExecStart=/usr/bin/docker run --rm --name ibtaxi-osrm -p ${OSRM_BIND}:5000:5000 -v ${OSRM_DIR}:/data ${IMAGE} osrm-routed --algorithm mld --max-table-size 1000 /data/area.osrm
ExecStop=/usr/bin/docker stop ibtaxi-osrm
# Ovoz sifati uchun: Asterisk'dan past ustuvorlik (reja §9)
CPUWeight=50

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable ibtaxi-osrm
sudo systemctl restart ibtaxi-osrm

echo "==> Tayyor"
