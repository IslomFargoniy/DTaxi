#!/usr/bin/env bash
# Testlar demo serverda (Mac'da PostgreSQL+PostGIS yo'q — lokal VM o'chirilgan): ishchi papka (commit qilinmaganlari
# bilan) serverdagi ~/dtaxi-tests ga rsync qilinadi va u yerda `php artisan test`. Test bazasi — alohida dtaxi_test
# roli: asosiy `dtaxi` bazasiga ulanish huquqi yo'q, xato sozlamada ham ishlab turgan ma'lumotga tegmaydi.
#
#   DTAXI_SSH_PASSWORD='...' deploy/test-remote.sh                  # hammasi
#   DTAXI_SSH_PASSWORD='...' deploy/test-remote.sh --filter=Client  # pest argumentlari
#
# Pint, PHPStan, npm run check, tsc — Mac'da (bazasiz).
set -euo pipefail

HOST="${DTAXI_TEST_HOST:-taxi@193.181.218.203}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [[ -n "${DTAXI_SSH_PASSWORD:-}" ]]; then
    ASKPASS="$(mktemp)"
    trap 'rm -f "$ASKPASS"' EXIT
    printf '#!/bin/sh\nprintf "%%s\\n" "$DTAXI_SSH_PASSWORD"\n' > "$ASKPASS"
    chmod 700 "$ASKPASS"
    export SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force DISPLAY=x DTAXI_SSH_PASSWORD
fi
SSH="ssh -o ConnectTimeout=20 -o ServerAliveInterval=20"

# vendor serverda o'zi (composer.lock o'zgarsa qayta); .env serverniki — test bazasi paroli bilan
rsync -az --delete -e "$SSH" \
    --exclude=.git --exclude=node_modules --exclude=vendor --exclude=android --exclude=.env \
    --exclude=public/map --exclude=public/android --exclude=public/hot --exclude=storage/logs \
    --exclude='storage/app/*' --exclude='storage/framework/*' --exclude=bootstrap/cache/ \
    "$ROOT/" "$HOST:dtaxi-tests/"

$SSH "$HOST" bash -s -- "$@" <<'REMOTE'
set -euo pipefail
cd ~/dtaxi-tests
mkdir -p storage/app/private storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
if [[ ! -f .env ]]; then
    cp .env.example .env
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/; s/^DB_USERNAME=.*/DB_USERNAME=dtaxi_test/; s/^DB_PASSWORD=.*/DB_PASSWORD=$(cat ~/.dtaxi-test-db-pass)/; s/^DB_DATABASE=.*/DB_DATABASE=dtaxi_test/" .env
    sed -i "s/^CACHE_STORE=.*/CACHE_STORE=array/; s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/; s/^REDIS_HOST=.*/REDIS_HOST=127.0.0.1/" .env
fi
if ! cmp -s composer.lock vendor/.composer.lock 2>/dev/null; then
    composer install -q --no-interaction --no-progress
    cp composer.lock vendor/.composer.lock
fi
grep -q '^APP_KEY=base64' .env || php artisan key:generate -q
php artisan config:clear -q
php artisan test "$@"
REMOTE
