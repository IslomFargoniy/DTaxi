# Dtaxi — firma serveriga o'rnatish

Har bir taksi firmasi o'z serverida alohida nusxa oladi. Firmaga xos hech narsa kodda yo'q: kalitlar (SIP trunk, Click, Payme, SpeechKit, Firebase) o'rnatilgandan keyin panelda kiritiladi.

---

## 1. Oldindan tayyorlash

### Server

|      | Minimal                                 | Tavsiya                                     |
| ---- | --------------------------------------- | ------------------------------------------- |
| OS   | **Ubuntu 24.04 LTS** (toza)             | —                                           |
| CPU  | 4 vCPU                                  | 6–8 vCPU                                    |
| RAM  | 8 GB                                    | 16 GB                                       |
| Disk | 100 GB SSD                              | 200 GB NVMe (qo'ng'iroq yozuvlari ~7 GB/oy) |
| IP   | **Serverning o'zida ochiq (public) IP** | —                                           |

> **Muhim — ochiq IP.** Operator brauzeri va haydovchi ilovasi ovozni (WebRTC) to'g'ridan-to'g'ri serverga yuboradi.
> Server NAT ortida bo'lsa (uy/ofis routeri, ba'zi bulut VPS'lari 10.x ichki IP bilan) ovoz bir tomonlama bo'lib qoladi.
> Buni oldindan tekshiring: `ip -4 addr` chiqishida ochiq IP ko'rinishi kerak.

### Domen va DNS

- Domen (masalan `taxi.firma.uz`) uchun **A yozuvi server IP'siga** o'rnatilgan va tarqalgan bo'lsin: `dig +short taxi.firma.uz` server IP'sini qaytarsin.
- DNS tayyor bo'lmasa, Let's Encrypt sertifikati olinmaydi va o'rnatish to'xtaydi (§8 ga qarang).

### Firmadan olinadigan ma'lumotlar

| Nima                                                                    | Qayerga kiritiladi                      | Majburiymi                  |
| ----------------------------------------------------------------------- | --------------------------------------- | --------------------------- |
| Domen, admin e-mail (sertifikat uchun)                                  | `install.sh` parametrlari               | ha                          |
| SIP trunk: provayder (Sarkor va h.k.), login, parol, server, raqam(lar) | Panel → Telefoniya                      | qo'ng'iroqlar uchun         |
| Firebase loyihasi (`google-services.json` + server kaliti)              | Android build + Panel → Integratsiyalar | push uchun                  |
| Click / Payme kalitlari                                                 | Panel → Integratsiyalar                 | onlayn to'ldirish uchun     |
| Yandex SpeechKit kaliti                                                 | Panel → Integratsiyalar                 | IVR ovozli iboralar uchun   |
| Zaxira uchun SFTP server                                                | Panel → Integratsiyalar                 | tavsiya etiladi             |
| Xizmat hududi (viloyatlar)                                              | `BBOX` o'zgaruvchisi                    | standart — Farg'ona vodiysi |

---

## 2. O'rnatish

```bash
# root sifatida
apt-get update && apt-get install -y git
git clone git@github.com:IslomFargoniy/ibTaxi.git /var/www/ibtaxi
cd /var/www/ibtaxi
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz
```

Parametrlar:

| Parametr / o'zgaruvchi      | Ma'nosi                                                                           |
| --------------------------- | --------------------------------------------------------------------------------- |
| `--prod --domain= --email=` | firma serveri (majburiy uchtasi birga)                                            |
| `--skip-maps`               | xarita/OSRM tayyorlashni o'tkazib yuborish (keyin qo'lda)                         |
| `--skip-ssl`                | sertifikatsiz — **faqat sinov uchun** (mikrofon https'siz ishlamaydi)             |
| `BBOX=lon1,lat1,lon2,lat2`  | xizmat hududi (standart — Farg'ona vodiysi)                                       |
| `PUBLIC_IP=`                | avtomatik aniqlanmasa                                                             |
| `LOCAL_NET=`                | server ichki tarmog'i (standart `192.168.0.0/16`; bulutda ko'pincha `10.0.0.0/8`) |

Git uchun: serverda `ibtaxi` foydalanuvchisiga **faqat o'qish huquqli deploy key** qo'shing (GitHub → Settings → Deploy keys), keyingi yangilanishlar shu orqali keladi.

### `install.sh` nima qiladi (tartib bo'yicha)

1. Paketlar: nginx, supervisor, redis, php8.3-fpm, PostgreSQL 16 + PostGIS 3, certbot, ufw, fail2ban; `ibtaxi` tizim foydalanuvchisi.
2. RAM+swap 8 GB dan kam bo'lsa — 4 GB swap.
3. Composer, Node 22.
4. PostgreSQL: `ibtaxi` roli va bazasi, `postgis`, `pg_trgm` kengaytmalari (parol tasodifiy).
5. Huquqlar: php-fpm `ibtaxi` nomidan ishlaydi, papka `750`.
6. `.env` (production, baza, Reverb kalitlari) — fayl `640`.
7. Asterisk + ODBC: `deploy/asterisk/*.conf` → `/etc/asterisk`, DTLS sertifikati, yozuvlar papkasi, ARI paroli `.env` ga.
8. Nginx: `/` → PHP, `/app` → Reverb (8080), `/ws` → Asterisk WebSocket (8088), `/map/` → xarita tile'lari.
9. `composer install`, `npm ci && npm run build`, `migrate`, rollar, `storage:link`, `optimize`, `telephony:sync`.
10. Xarita (`--skip-maps` bo'lmasa, **bir necha soat**): OSM → tile'lar → OSRM (Docker, `ibtaxi-osrm`) → hududlar, mo'ljallar, ko'cha/uy raqamlari.
11. Let's Encrypt sertifikati (`certbot --nginx --redirect`), yangilanish — certbot timer.
12. Firewall (ufw) va fail2ban.
13. logrotate, supervisor dasturlari, cron (`schedule:run` har daqiqada).

### O'rnatishdan so'ng darhol

1. **Darhol** `https://taxi.firma.uz` ni oching va sozlash ustasidan o'ting — birinchi kirgan odam superadmin bo'ladi.
   Sayt ochiq turgan paytda buni kechiktirmang.
2. Panel → Filiallar: filial(lar), xarita markazi, vaqt zonasi.
3. Panel → Telefoniya: SIP trunk va raqamlar → saqlash. 5 daqiqa ichida firewall trunk IP'lariga 5060 ni ochadi.
4. Panel → Integratsiyalar: Firebase, Click/Payme, SpeechKit, zaxira SFTP.
5. Xodimlar (operator, kassir) va haydovchilar.
6. Tekshiruv (§5).

---

## 3. Portlar va xizmatlar

| Port                         | Kimga ochiq             | Nima                                                    |
| ---------------------------- | ----------------------- | ------------------------------------------------------- |
| 22/tcp                       | hammaga (fail2ban)      | SSH                                                     |
| 80, 443/tcp                  | hammaga                 | panel, API, WSS (Reverb, Asterisk)                      |
| 10000–20000/udp              | hammaga                 | ovoz (RTP) — brauzer, ilova, trunk                      |
| 5060/udp                     | **faqat trunk IP'lari** | SIP trunk (`deploy/sip-firewall.sh`, cron har 5 daqiqa) |
| 5432, 6379, 8080, 8088, 5000 | faqat 127.0.0.1         | PostgreSQL, Redis, Reverb, Asterisk HTTP/ARI, OSRM      |

| Xizmat                           | Boshqaruv                              | Log                           |
| -------------------------------- | -------------------------------------- | ----------------------------- |
| Reverb (WebSocket)               | `supervisorctl restart ibtaxi-reverb`  | `/var/log/ibtaxi-reverb.log`  |
| Horizon (navbatlar)              | `supervisorctl restart ibtaxi-horizon` | `/var/log/ibtaxi-horizon.log` |
| ARI daemon (qo'ng'iroq mantig'i) | `php artisan ari:restart`              | `/var/log/ibtaxi-ari.log`     |
| Asterisk                         | `systemctl restart asterisk`           | `/var/log/asterisk/messages`  |
| OSRM                             | `systemctl restart ibtaxi-osrm`        | `journalctl -u ibtaxi-osrm`   |
| Laravel                          | —                                      | `storage/logs/laravel.log`    |

Rejali vazifalar (cron → `schedule:run`):

- har daqiqada: `orders:dispatch-scheduled`, `orders:rebroadcast`;
- har 5 daqiqada: `ibtaxi:health`, `drivers:release-stuck`;
- 02:30 `ibtaxi:backup`, 03:30 `recordings:cleanup`, 04:00 `locations:prune`.

---

## 4. Haydovchi ilovasi (Android)

Har firma uchun alohida ilova: `android/firms/<firma>/` (batafsil — `android/firms/README.md`).

```bash
# Mac'da, bir marta
mkdir android/firms/<firma>
#   firm.properties: applicationId=uz.<firma>.driver, appName, serverUrl=https://taxi.firma.uz, primaryColor
#   google-services.json (firmaning Firebase'i), logo.png (ixtiyoriy)
keytool -genkeypair -v -keystore android/firms/<firma>/keystore.jks -alias driver -keyalg RSA -keysize 2048 -validity 36500
#   keystore.properties: storePassword, keyAlias, keyPassword

# har versiyada
cd android
JAVA_HOME=~/Library/Java/JavaVirtualMachines/jdk-17*/Contents/Home ./gradlew assembleRelease -Pfirm=<firma>
# natija: public/android/dtaxi.<versiya>.apk + public/android/version.json
scp ../public/android/dtaxi.*.apk ../public/android/version.json root@taxi.firma.uz:/var/www/ibtaxi/public/android/
ssh root@taxi.firma.uz chown -R ibtaxi:ibtaxi /var/www/ibtaxi/public/android
```

- **`keystore.jks` va parollarni yo'qotmang va git'ga qo'shmang.** Yo'qolsa, haydovchilar ilovani o'chirib qayta o'rnatishi kerak bo'ladi (yangilanish o'rnatilmaydi).
- Ilova `version.json` ni o'qib, yangi versiyani o'zi taklif qiladi.
- Birinchi o'rnatish: haydovchi `https://taxi.firma.uz/android/dtaxi.<versiya>.apk` dan yuklaydi va "noma'lum manbalardan o'rnatish"ga ruxsat beradi.
- Telefonda: joylashuv ruxsati — "Har doim", batareya tejash — o'chirilgan (ilova sozlamalarida tugma bor).

---

## 5. Tekshiruv ro'yxati

```bash
sudo supervisorctl status              # ibtaxi-reverb, ibtaxi-horizon, ibtaxi-ari — RUNNING
sudo -u ibtaxi php artisan ibtaxi:health
asterisk -rx 'pjsip show registrations' # trunk — Registered
asterisk -rx 'pjsip show contacts'      # operatorlar/haydovchilar ulangan
ufw status | grep ibtaxi-sip            # trunk IP'lari 5060 uchun ochiq
curl -s 'http://127.0.0.1:5000/route/v1/driving/71.78,40.38;71.79,40.39' | head -c 100   # OSRM javob beradi
```

Qo'lda:

- [ ] Panelga kirish, til va mavzu almashtirish.
- [ ] Operator webphone'i "faol" (yashil).
- [ ] Firma raqamiga tashqi telefondan qo'ng'iroq → operatorga keladi, ikki tomonlama ovoz.
- [ ] Operator panelidan mijozga qo'ng'iroq.
- [ ] Haydovchi ilovasi: kirish, liniyaga chiqish, xaritada ko'rinadi.
- [ ] Buyurtma → haydovchiga taklif (ilova yopiq bo'lsa ham push orqali).
- [ ] Haydovchi → "Dispetcher" qo'ng'irog'i, ikki tomonlama ovoz.
- [ ] Kassa: balans to'ldirish; Click/Payme sinov to'lovi.
- [ ] `ibtaxi:backup` qo'lda ishga tushirib, zaxira SFTP'ga borganini tekshirish.

---

## 6. Yangilash va qaytarish

```bash
cd /var/www/ibtaxi
sudo -u ibtaxi php artisan ibtaxi:update           # oxirgi versiya
sudo -u ibtaxi php artisan ibtaxi:update --rollback # muammo bo'lsa — oldingi kod va baza
```

- **Har doim `sudo -u ibtaxi` bilan** ishga tushiring. root nomidan ishlatilsa, git "dubious ownership" xatosini beradi va fayllar root'ga o'tib qoladi.
- Yangilash: zaxira → texnik rejim → `git pull` → composer, build, migratsiya → xizmatlarni qayta ishga tushirish → `telephony:sync` → sayt ochiladi.
- **Faol qo'ng'iroqlar paytida yangilamang** (kechasi). ARI daemon qayta ishga tushganda davom etayotgan qo'ng'iroqlar yopilgan deb belgilanadi.
- `--ref=<teg>` dan keyin keyingi oddiy yangilanish ishlashi uchun avval `sudo -u ibtaxi git checkout main` qiling.
- `--rollback` yangilanishdan keyin yozilgan ma'lumotlarni (buyurtmalar, to'lovlar) o'chiradi — faqat yangilanishdan keyin darhol ishlating.
- `deploy/asterisk/*.conf` o'zgargan bo'lsa, ular avtomatik qo'llanmaydi: `install.sh` ni qayta ishga tushiring yoki faylni `/etc/asterisk` ga qo'lda ko'chirib, `asterisk -rx 'core reload'` qiling.

---

## 7. Zaxira va tiklash

- Har kuni 02:30: baza (`*.dump`) + `.env` + fayllar (`*.files.tar.gz`) → `storage/app/private/backups`, 14 kun saqlanadi.
- **Zaxira serverning o'zida turadi** — disk buzilsa yo'qoladi. Panel → Integratsiyalar → zaxira SFTP'ni albatta sozlang.
- Fayllar arxivida qo'ng'iroq yozuvlari ham bor — diskni kuzating (`df -h`).

Boshqa serverga tiklash:

```bash
# 1. Yangi serverga install.sh (yuqoridagidek), keyin:
sudo supervisorctl stop all
tar -xzf <sana>.files.tar.gz -C /var/www/ibtaxi       # .env va storage/app/private
#    .env dagi APP_KEY eski serverniki bo'lishi SHART — integratsiya kalitlari shu bilan shifrlangan
sudo -u postgres pg_restore --clean --if-exists --no-owner --role=ibtaxi -d ibtaxi <sana>.dump
sudo -u ibtaxi php artisan optimize && sudo -u ibtaxi php artisan telephony:sync
sudo supervisorctl start all
```

---

## 8. Ma'lum muammolar va yechimlar

| Belgi                                  | Sabab                                                              | Yechim                                                                                                                                                                                              |
| -------------------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `install.sh` certbot'da to'xtadi       | DNS hali serverga ko'rsatmaydi yoki Let's Encrypt limiti           | DNS'ni tekshirib, `install.sh` ni qayta ishga tushiring (qayta ishga tushirish xavfsiz). O'rnatish to'xtagan bo'lsa, firewall va supervisor hali sozlanmagan bo'lishi mumkin — oxirigacha yetkazing |
| Mikrofon ishlamaydi                    | sahifa `http://` orqali ochilgan                                   | faqat `https://`                                                                                                                                                                                    |
| Ovoz bir tomonlama                     | server NAT ortida, ochiq IP yo'q                                   | ochiq IP'li server; yoki `LOCAL_NET` noto'g'ri — to'g'rilab `install.sh` ni qayta ishga tushiring                                                                                                   |
| Trunk "Unregistered"                   | login/parol, provayder IP'si                                       | Panel → Telefoniya; `asterisk -rx 'pjsip show registrations'`; `ufw status`                                                                                                                         |
| Kiruvchi qo'ng'iroq kelmaydi           | 5060 trunk IP'siga ochilmagan                                      | `sudo /var/www/ibtaxi/deploy/sip-firewall.sh`, `journalctl -t ibtaxi-sip`                                                                                                                           |
| Telefoniya sozlamalari ta'sir qilmaydi | ARI daemon eski sozlamani ushlab turadi                            | `sudo -u ibtaxi php artisan ari:restart`                                                                                                                                                            |
| Operator "band" bo'lib qoldi           | qo'ng'iroq holati osilib qolgan (Asterisk qayta ishga tushgan)     | `ari:restart`                                                                                                                                                                                       |
| Haydovchiga push kelmaydi              | Firebase kaliti yo'q yoki `google-services.json` boshqa loyihaniki | Integratsiyalar va Android build'ni tekshiring                                                                                                                                                      |
| Xarita bo'sh                           | tile'lar tayyorlanmagan (`--skip-maps`)                            | `deploy/osm-prepare.sh`, `map-prepare.sh`, `osrm-prepare.sh`, so'ng `regions:import`                                                                                                                |

---

## 9. Lokal ishlab chiqish (faqat dasturchi uchun)

- `sudo ./deploy/install.sh --dev` — Multipass VM (`ibtaxi`, 192.168.252.2); self-signed https.
- Haqiqiy telefonda sinov: Mac'da `python3 deploy/dev-lan-proxy.py` (80/443 va RTP portlarini VM'ga uzatadi, Asterisk'ni Mac IP'siga sozlaydi), ilova `-Pfirm=lan` bilan.
- Bu skript va `--dev` rejimi firma serverida **ishlatilmaydi**.
