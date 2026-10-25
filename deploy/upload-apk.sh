#!/usr/bin/env bash
# Haydovchi ilovasini firma serveriga joylash (deploy.md §4): 4 MB bo'laklab, har bo'lak SHA-256 bilan tekshirilib,
# uzilsa qayta yuboriladi; butun fayl tekshiruvdan o'tgandagina joylanadi va version.json almashtiriladi —
# yarim yuklangan APK haydovchilarga hech qachon taklif qilinmaydi. Serverda faqat oxirgi versiya qoladi.
#
#   deploy/upload-apk.sh root@1.2.3.4            # public/android dagi oxirgi build
#   deploy/upload-apk.sh root@1.2.3.4 --client   # mijoz ilovasi (sinov uchun; asosiy tarqatish — Google Play) → /android/client/
#   DTAXI_SSH_PASSWORD='...' deploy/upload-apk.sh taxi@1.2.3.4
set -euo pipefail

[[ $# -ge 1 ]] || { sed -n '2,9p' "$0"; exit 1; }
HOST="$1"
CLIENT=0
[[ "${2:-}" == "--client" ]] && CLIENT=1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ "$CLIENT" == 1 ]]; then
    # Mijoz ilovasi: version.json yo'q (Google Play yangilaydi), papkada faqat oxirgisi
    APK="$(ls -t "$ROOT"/public/android/client/dtaxi-client.*.apk 2>/dev/null | grep -v -- -debug | head -1)"
    [[ -n "$APK" ]] || { echo "APK topilmadi (avval ./gradlew :client:assembleRelease -Pfirm=<firma>)" >&2; exit 1; }
    TARGET_DIR="/var/www/dtaxi/public/android/client"
else
    VJ="$ROOT/public/android/version.json"
    APK="$ROOT/public/android/$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["url"].rsplit("/",1)[1])' "$VJ")"
    [[ -f "$APK" ]] || { echo "APK topilmadi: $APK (avval ./gradlew :driver:assembleRelease -Pfirm=<firma>)" >&2; exit 1; }
    TARGET_DIR="/var/www/dtaxi/public/android"
fi
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

if [[ "$CLIENT" == 1 ]]; then
    PUBLISH="sudo find $TARGET_DIR -name 'dtaxi-client.*.apk' -delete 2>/dev/null; true"
else
    scp -q "${OPTS[@]}" "$VJ" "$HOST:$REMOTE_DIR/version.json"
    PUBLISH="sudo install -o dtaxi -g dtaxi -m 644 version.json $TARGET_DIR/version.json"
fi
ssh "${OPTS[@]}" "$HOST" "cd $REMOTE_DIR && cat part.* > app.apk && [ \"\$(sha256sum app.apk | cut -c1-64)\" = \"$WANT\" ] \
    && sudo install -d -o dtaxi -g dtaxi $TARGET_DIR \
    && { $PUBLISH; } \
    && sudo install -o dtaxi -g dtaxi -m 644 app.apk $TARGET_DIR/$NAME \
    && { sudo find $TARGET_DIR -maxdepth 1 -name 'dtaxi.*.apk' ! -name '$NAME' -delete; true; } \
    && rm -rf $REMOTE_DIR && echo \"Joylandi: $NAME\" || { echo 'Tekshiruv summasi mos emas — joylanmadi' >&2; exit 1; }"
