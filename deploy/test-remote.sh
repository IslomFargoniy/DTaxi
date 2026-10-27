#!/usr/bin/env bash
# Testlar demo serverda (Mac'da PostgreSQL+PostGIS yo'q — lokal VM o'chirilgan): ishchi papka (commit qilinmaganlari
# bilan) serverdagi ~/dtaxi-tests ga rsync qilinadi va u yerda `php artisan test`. Xavfsizlik:
#   - alohida `dtaxi-test` foydalanuvchisi — sudo'siz, faqat SSH kalit bilan (~/.ssh/dtaxi_test_ed25519);
#   - test bazasi — alohida dtaxi_test roli: asosiy `dtaxi` bazasiga ulanish huquqi yo'q.
#
#   deploy/test-remote.sh                  # hammasi
#   deploy/test-remote.sh --filter=Client  # pest argumentlari
#
# Pint, PHPStan, npm run check, tsc — Mac'da (bazasiz).
set -euo pipefail

HOST="${DTAXI_TEST_HOST:-dtaxi-test@193.181.218.203}"
KEY="${DTAXI_TEST_KEY:-$HOME/.ssh/dtaxi_test_ed25519}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=20"

# vendor serverda o'zi (composer.lock o'zgarsa qayta); .env serverniki — test bazasi paroli bilan
rsync -az --delete -e "$SSH" \
    --exclude=.git --exclude=node_modules --exclude=vendor --exclude=android --exclude=.env \
    --exclude=public/map --exclude=public/android --exclude=public/hot --exclude=storage/logs \
    --exclude='storage/app/*' --exclude='storage/framework/*' --exclude=bootstrap/cache/ \
    "$ROOT/" "$HOST:dtaxi-tests/"

# Argumentlar SSH orqali bitta qatorga qo'shiladi — bo'sh joyli filtr bo'linmasin
$SSH "$HOST" bash -s -- $(printf '%q ' "$@") <<'REMOTE'
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
