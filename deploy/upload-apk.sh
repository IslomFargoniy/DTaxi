#!/usr/bin/env bash
# Haydovchi ilovasini firma serveriga joylash (deploy.md §4): 4 MB bo'laklab, har bo'lak SHA-256 bilan tekshirilib,
# uzilsa qayta yuboriladi; butun fayl tekshiruvdan o'tgandagina joylanadi va version.json almashtiriladi —
# yarim yuklangan APK haydovchilarga hech qachon taklif qilinmaydi.
#
#   deploy/upload-apk.sh root@1.2.3.4            # public/android dagi oxirgi build
#   DTAXI_SSH_PASSWORD='...' deploy/upload-apk.sh taxi@1.2.3.4
set -euo pipefail

[[ $# -ge 1 ]] || { sed -n '2,8p' "$0"; exit 1; }
HOST="$1"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VJ="$ROOT/public/android/version.json"
APK="$ROOT/public/android/$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["url"].rsplit("/",1)[1])' "$VJ")"
[[ -f "$APK" ]] || { echo "APK topilmadi: $APK (avval ./gradlew assembleRelease -Pfirm=<firma>)" >&2; exit 1; }
NAME="$(basename "$APK")"

if [[ -n "${DTAXI_SSH_PASSWORD:-}" ]]; then
    ASKPASS="$(mktemp)"
    printf '#!/bin/sh\nprintf "%%s\\n" "$DTAXI_SSH_PASSWORD"\n' > "$ASKPASS"
    chmod 700 "$ASKPASS"
    export SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force DISPLAY=x DTAXI_SSH_PASSWORD
fi
OPTS=(-o ConnectTimeout=20 -o ServerAliveInterval=15)
PARTS="$(mktemp -d)"
trap 'rm -rf "$PARTS" ${ASKPASS:+"$ASKPASS"}' EXIT

split -b 4m "$APK" "$PARTS/part."
WANT="$(shasum -a 256 "$APK" | cut -c1-64)"
REMOTE_DIR="/tmp/dtaxi-upload-$NAME"
ssh "${OPTS[@]}" "$HOST" "mkdir -p $REMOTE_DIR"

total=$(ls "$PARTS"/part.* | wc -l | tr -d ' ')
i=0
for part in "$PARTS"/part.*; do
    i=$((i + 1))
    n="$(basename "$part")"
    want="$(shasum -a 256 "$part" | cut -c1-64)"
    # Qayta ishga tushirilganda tayyor bo'laklar qayta yuborilmaydi
    for attempt in $(seq 1 10); do
        got="$(ssh "${OPTS[@]}" "$HOST" "sha256sum $REMOTE_DIR/$n 2>/dev/null | cut -c1-64" || true)"
        [[ "$got" == "$want" ]] && break
        scp -q "${OPTS[@]}" "$part" "$HOST:$REMOTE_DIR/$n" || sleep 3
    done
    [[ "$got" == "$want" ]] || { echo "Bo'lak $n yuklanmadi — qayta ishga tushiring (tayyorlari saqlanadi)" >&2; exit 1; }
    echo "  $i/$total"
done

scp -q "${OPTS[@]}" "$VJ" "$HOST:$REMOTE_DIR/version.json"
ssh "${OPTS[@]}" "$HOST" "cd $REMOTE_DIR && cat part.* > app.apk && [ \"\$(sha256sum app.apk | cut -c1-64)\" = \"$WANT\" ] \
    && sudo install -d -o dtaxi -g dtaxi /var/www/dtaxi/public/android \
    && sudo install -o dtaxi -g dtaxi -m 644 app.apk /var/www/dtaxi/public/android/$NAME \
    && sudo install -o dtaxi -g dtaxi -m 644 version.json /var/www/dtaxi/public/android/version.json \
    && rm -rf $REMOTE_DIR && echo \"Joylandi: $NAME\" || { echo 'Tekshiruv summasi mos emas — joylanmadi' >&2; exit 1; }"
