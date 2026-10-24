#!/usr/bin/env bash
# Mac'dan bitta buyruq bilan yangi serverga o'rnatish (deploy.md §0): git, deploy key, kod, install.sh fonda,
# jarayon shu terminalda kuzatiladi. Server — toza Ubuntu 24.04, foydalanuvchi root yoki parolsiz sudo.
#
#   deploy/remote-install.sh root@1.2.3.4 --domain=taxi.firma.uz --email=admin@firma.uz [--demo] [--company="..."] \
#       [--map-from=taxi@193.181.218.203] [install.sh ning boshqa parametrlari]
#
#   --map-from=<demo server> — xarita (tile, OSRM, tuzatilgan nomlar) demo serverdan: u yerda dtaxi:map-export,
#       yangi server to'plamni to'g'ridan-to'g'ri yuklab oladi (Mac orqali o'tmaydi) va install.sh --map-bundle bilan
#       OSM/tile/OSRM qayta yasalmaydi (~30 daqiqa o'rniga soatlar). Muqobil: --map-dump=<lokal fayl> (faqat nomlar).
#   SSH paroli bilan (kalit yo'q bo'lsa): DTAXI_SSH_PASSWORD='...' (demo uchun boshqa bo'lsa DTAXI_MAP_SSH_PASSWORD)
#
# Qayta ishga tushirish xavfsiz: kod bor bo'lsa klonlanmaydi, install.sh tayyor qismlarni o'tkazib yuboradi.
# Uzilsa — shu buyruqni qayta bering (yoki faqat kuzatish: --follow).
set -euo pipefail

REPO="git@github.com:IslomFargoniy/DTaxi.git"
APP_DIR="/var/www/dtaxi"
LOG="/var/log/dtaxi-install.log"

[[ $# -ge 1 ]] || { sed -n '2,12p' "$0"; exit 1; }
HOST="$1"; shift
DOMAIN=""
MAP_DUMP=""
MAP_FROM=""
FOLLOW_ONLY=0
INSTALL_ARGS=()
for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#*=}"; INSTALL_ARGS+=("$arg") ;;
        --map-dump=*) MAP_DUMP="${arg#*=}" ;;
        --map-from=*) MAP_FROM="${arg#*=}" ;;
        --follow) FOLLOW_ONLY=1 ;;
        *) INSTALL_ARGS+=("$arg") ;;
    esac
done

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mOGOHLANTIRISH: %s\033[0m\n' "$*" >&2; }

# Parol bilan kirish: Mac'da sshpass yo'q — SSH_ASKPASS orqali (parol har chaqiruvda ASKPASS_PW dan)
if [[ -n "${DTAXI_SSH_PASSWORD:-}${DTAXI_MAP_SSH_PASSWORD:-}" ]]; then
    ASKPASS="$(mktemp)"
    trap 'rm -f "$ASKPASS"' EXIT
    printf '#!/bin/sh\nprintf "%%s\\n" "$ASKPASS_PW"\n' > "$ASKPASS"
    chmod 700 "$ASKPASS"
    export SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force DISPLAY=x
fi
SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 -o ServerAliveInterval=20)
remote() { ASKPASS_PW="${DTAXI_SSH_PASSWORD:-}" ssh "${SSH_OPTS[@]}" "$HOST" "$@"; }
demo() { ASKPASS_PW="${DTAXI_MAP_SSH_PASSWORD:-${DTAXI_SSH_PASSWORD:-}}" ssh "${SSH_OPTS[@]}" "$MAP_FROM" "$@"; }

follow() {
    log "O'rnatish jarayoni ($LOG) — Ctrl+C kuzatishni to'xtatadi, o'rnatish serverda davom etadi"
    local seen=0 out lines n
    while true; do
        # [d]eploy — pgrep o'z buyrug'ini topib olmasin
        if ! out=$(remote "sudo sed 's/\x1b\[[0-9;]*m//g' $LOG 2>/dev/null | grep -aE '==>|OGOHLANTIRISH' ; pgrep -f '[d]eploy/install.sh' >/dev/null && echo @@RUN || echo @@END; df -h / | tail -1 | awk '{print \"@@DISK \" \$4 \" bo'sh\"}'" 2>/dev/null); then
            sleep 30; continue
        fi
        lines=$(grep -v '^@@' <<<"$out" || true)
        n=$(grep -c . <<<"$lines" || true)
        if (( n > seen )); then
            tail -n $((n - seen)) <<<"$lines"
            grep '^@@DISK' <<<"$out" | sed 's/^@@DISK /    disk: /'
            seen=$n
        fi
        if grep -q '^@@END' <<<"$out"; then
            log "Tugadi. Yakuniy chiqish:"
            remote "sudo sed 's/\x1b\[[0-9;]*m//g' $LOG | sed -n '/==> Tayyor\$/,\$p' | tail -20"
            return
        fi
        sleep 45
    done
}

if [[ "$FOLLOW_ONLY" == 1 ]]; then follow; exit 0; fi
[[ -n "$DOMAIN" ]] || { echo "--domain= kerak" >&2; exit 1; }
if [[ -n "$MAP_DUMP" && ! -f "$MAP_DUMP" ]]; then echo "Fayl topilmadi: $MAP_DUMP" >&2; exit 1; fi

log "Server: $HOST"
remote 'sudo -n true' || { echo "Foydalanuvchida parolsiz sudo yo'q" >&2; exit 1; }
remote '. /etc/os-release; echo "OS: $PRETTY_NAME"; echo "CPU: $(nproc) vCPU"; free -m | awk "/^Mem:/{print \"RAM: \" \$2 \" MB\"}"; df -h / | awk "NR==2{print \"Disk: \" \$2 \" (bo'\''sh \" \$4 \")\"}"; ip -4 -o addr show scope global | awk "{print \"IP: \" \$4}"'
read -r CPUS RAM_MB DISK_GB < <(remote 'echo "$(nproc) $(free -m | awk "/^Mem:/{print \$2}") $(df -BG --output=size / | tail -1 | tr -dc 0-9)"')
# Butun O'zbekiston OSRM'i 1 vCPU / 2 GB'da soatlab cho'ziladi yoki to'xtaydi; o'rnatish paytida resurs qo'shilsa server qayta yuklanib, o'rnatish uziladi
if (( CPUS < 2 || RAM_MB < 3500 || DISK_GB < 24 )); then
    warn "Server kichik (kamida 2 vCPU / 4 GB RAM / 25 GB disk; firma uchun 4 / 8 / 100). Kattalashtirish kerak bo'lsa — HOZIR, o'rnatishdan oldin."
    read -r -p "Shu holatda davom etilsinmi? [y/N] " answer
    [[ "$answer" == [yY]* ]] || exit 1
fi

SERVER_IP="${HOST#*@}"
RESOLVED="$(dig +short "$DOMAIN" @8.8.8.8 | tail -1)"
if [[ "$RESOLVED" != "$SERVER_IP" ]]; then
    warn "$DOMAIN → '${RESOLVED:-topilmadi}', server $SERVER_IP — DNS A yozuvi to'g'rilanmaguncha SSL sertifikati olinmaydi"
    read -r -p "Davom etilsinmi? [y/N] " answer
    [[ "$answer" == [yY]* ]] || exit 1
fi

log "git va dtaxi foydalanuvchisi, deploy key"
PUBKEY=$(remote 'sudo apt-get -o DPkg::Lock::Timeout=600 update -q >/dev/null
sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 install -y -q git >/dev/null
id -u dtaxi >/dev/null 2>&1 || sudo useradd --system --create-home --shell /bin/bash dtaxi
sudo -u dtaxi -H bash -c "mkdir -p ~/.ssh && chmod 700 ~/.ssh
    [ -f ~/.ssh/id_ed25519 ] || ssh-keygen -q -t ed25519 -N \"\" -C dtaxi@$(hostname) -f ~/.ssh/id_ed25519
    grep -q github.com ~/.ssh/known_hosts 2>/dev/null || ssh-keyscan -t ed25519 github.com 2>/dev/null >> ~/.ssh/known_hosts
    cat ~/.ssh/id_ed25519.pub"')

until remote "sudo -u dtaxi -H git ls-remote $REPO HEAD >/dev/null 2>&1"; do
    echo
    echo "Bu kalitni GitHub → DTaxi → Settings → Deploy keys → Add deploy key ga qo'shing"
    echo "(nomi: $DOMAIN, \"Allow write access\" belgilanmasin):"
    echo
    echo "    $PUBKEY"
    echo
    read -r -p "Qo'shgach Enter bosing... "
done
echo "GitHub'ga kirish bor."

log "Kod: $APP_DIR"
remote "if sudo test -d $APP_DIR/.git; then echo 'Kod bor — klonlanmaydi (yangilash: dtaxi:update)'; else
    sudo mkdir -p $APP_DIR && sudo chown dtaxi:dtaxi $APP_DIR && sudo -u dtaxi -H git clone -q $REPO $APP_DIR && sudo -u dtaxi git -C $APP_DIR log --oneline -1; fi"

if [[ -n "$MAP_FROM" ]] && ! remote 'sudo test -f /root/dtaxi-map.tar'; then
    log "Xarita to'plami demo serverdan: $MAP_FROM"
    BUNDLE=$(demo "cd /tmp && sudo -u dtaxi -H bash -c 'cd $APP_DIR && php artisan dtaxi:map-export' | sed -n 's/^Xarita to.plami: \(\S*\) .*/\1/p'")
    [[ -n "$BUNDLE" ]] || { echo "Demo serverda dtaxi:map-export to'plam yaratmadi" >&2; exit 1; }
    DEMO_URL=$(demo "sudo grep '^APP_URL=' $APP_DIR/.env | cut -d= -f2-")
    WANT=$(demo "sudo sha256sum '$BUNDLE' | cut -c1-64")
    # Tasodifiy manzil faqat yuklash davomida — public/ ostida, nginx statik fayl sifatida beradi
    TOKEN=$(openssl rand -hex 16)
    demo "sudo install -d -o dtaxi -g dtaxi -m 755 $APP_DIR/public/map-transfer-$TOKEN && sudo ln '$BUNDLE' $APP_DIR/public/map-transfer-$TOKEN/map.tar && sudo chmod 644 $APP_DIR/public/map-transfer-$TOKEN/map.tar"
    # Qattiq havola — ruxsat asl fayl bilan umumiy: yuklab bo'lingach yana faqat dtaxi o'qiydi
    cleanup_transfer() { demo "sudo rm -rf $APP_DIR/public/map-transfer-$TOKEN; sudo chmod 600 '$BUNDLE'" || true; }
    echo "    $(demo "du -h '$BUNDLE' | cut -f1") — yangi server yuklab olmoqda..."
    if ! remote "sudo curl -fsS --retry 5 -C - -o /root/dtaxi-map.tar.part '$DEMO_URL/map-transfer-$TOKEN/map.tar' && [ \"\$(sudo sha256sum /root/dtaxi-map.tar.part | cut -c1-64)\" = '$WANT' ] && sudo mv /root/dtaxi-map.tar.part /root/dtaxi-map.tar"; then
        cleanup_transfer
        echo "Xarita to'plami yuklanmadi yoki tekshiruv summasi mos emas" >&2
        exit 1
    fi
    cleanup_transfer
fi
if [[ -n "$MAP_FROM" ]]; then INSTALL_ARGS+=("--map-bundle=/root/dtaxi-map.tar"); fi

if [[ -n "$MAP_DUMP" ]]; then
    log "Bazadagi xarita: $MAP_DUMP"
    scp -q "${SSH_OPTS[@]}" "$MAP_DUMP" "$HOST:/tmp/dtaxi-map.dump"
    remote 'sudo install -m 600 /tmp/dtaxi-map.dump /root/dtaxi-map.dump && rm /tmp/dtaxi-map.dump'
    INSTALL_ARGS+=("--map-dump=/root/dtaxi-map.dump")
fi

if remote "pgrep -f '[d]eploy/install.sh' >/dev/null"; then
    warn "install.sh allaqachon ishlayapti — faqat kuzatamiz"
else
    log "install.sh --prod ${INSTALL_ARGS[*]}"
    QUOTED=$(printf '%q ' "${INSTALL_ARGS[@]}")
    # setsid — SSH uzilsa ham davom etadi; log /var/log da — server qayta yuklansa ham qoladi
    remote "sudo setsid bash -c \"cd $APP_DIR && ./deploy/install.sh --prod $QUOTED > $LOG 2>&1 < /dev/null\" > /dev/null 2>&1 < /dev/null &"
    sleep 5
fi

follow
