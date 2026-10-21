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
> Ochiq IP bo'lmasa yoki operatorlar UDP yopiq tarmoqda (korporativ ofis, mehmonxona Wi-Fi, ba'zi mobil operatorlar) ishlasa — `--turn` (§2.1).

### Domen va DNS

- Domen (masalan `taxi.firma.uz`) uchun **A yozuvi server IP'siga** o'rnatilgan va tarqalgan bo'lsin: `dig +short taxi.firma.uz` server IP'sini qaytarsin.
- DNS tayyor bo'lmasa, Let's Encrypt sertifikati olinmaydi: o'rnatish ogohlantirish bilan davom etadi, sertifikatni keyin olish kerak (§8 ga qarang).

### Firmadan olinadigan ma'lumotlar

| Nima                                                                    | Qayerga kiritiladi                      | Majburiymi                  |
| ----------------------------------------------------------------------- | --------------------------------------- | --------------------------- |
| Domen, admin e-mail (sertifikat uchun)                                  | `install.sh` parametrlari               | ha                          |
| Firma (xizmat) nomi                                                     | `--company=` yoki keyin Panel → Firma   | yo'q (standart — Dtaxi)     |
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
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --company="Ibrat taksi"
```

Parametrlar:

| Parametr / o'zgaruvchi      | Ma'nosi                                                                   |
| --------------------------- | ------------------------------------------------------------------------- |
| `--prod --domain= --email=` | firma serveri (majburiy uchtasi birga)                                    |
| `--company=`                | firma (xizmat) nomi — faqat birinchi o'rnatishda (standart `Dtaxi`)       |
| `--skip-maps`               | xarita/OSRM tayyorlashni o'tkazib yuborish (keyin qo'lda)                 |
| `--skip-ssl`                | sertifikatsiz — **faqat sinov uchun** (mikrofon https'siz ishlamaydi)     |
| `--turn`                    | ixtiyoriy TURN server (coturn) — §2.1                                     |
| `BBOX=lon1,lat1,lon2,lat2`  | xizmat hududi (standart — Farg'ona vodiysi)                               |
| `PUBLIC_IP=`                | avtomatik aniqlanmasa                                                     |
| `LOCAL_NET=`                | server ichki tarmog'i (standart — asosiy interfeys tarmog'idan avtomatik) |

Git uchun: serverda `ibtaxi` foydalanuvchisiga **faqat o'qish huquqli deploy key** qo'shing (GitHub → Settings → Deploy keys), keyingi yangilanishlar shu orqali keladi.

### 2.1. TURN server (ixtiyoriy, `--turn`)

WebRTC ovozi (operator brauzeri, haydovchi ilovasining qo'ng'iroq sahifasi) odatda to'g'ridan-to'g'ri Asterisk'ning RTP portlariga (UDP 10000–20000) boradi. Quyidagi hollarda bu ishlamaydi va ovoz yo'q yoki bir tomonlama bo'ladi — shunda TURN yoqiladi:

- **server NAT ortida** (bulut VPS 10.x ichki IP bilan, ofisdagi server router ortida);
- operator **korporativ tarmoqda** (UDP yopiq, faqat 443/TCP ochiq) yoki haydovchining mobil operatori UDP'ni cheklaydi.

```bash
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --turn
# yoki o'rnatilgan serverda alohida (qayta ishga tushirish xavfsiz):
sudo DOMAIN=taxi.firma.uz /var/www/ibtaxi/deploy/turn-config.sh
```

`deploy/turn-config.sh` nima qiladi:

- `coturn` o'rnatadi, `/etc/turnserver.conf` yozadi, `systemctl enable` + restart.
- `use-auth-secret`: tasodifiy `static-auth-secret` `.env` ga `TURN_SECRET` bo'lib tushadi (mavjud bo'lsa saqlanadi), `TURN_URL` — `turn:<domen>:3478` (UDP va TCP) va Let's Encrypt sertifikati bo'lsa `turns:<domen>:5349` (TLS; sertifikat `/etc/coturn/certs` ga ko'chiriladi, certbot yangilaganda hook qayta ko'chiradi).
- Panel va ilova har ulanishda **vaqtinchalik login/parol** oladi (12 soat, TURN REST API: login `<muddat>:<foydalanuvchi id>`, parol `base64(hmac-sha1(sir, login))`). Operator brauzeri muddat tugashidan oldin o'zi yangilaydi. Sozlanmagan bo'lsa faqat STUN beriladi.
- `realm` = domen; NAT aniqlanadi: ochiq IP ≠ ichki IP bo'lsa `external-ip=OCHIQ/ICHKI`, `relay-ip=ICHKI`.
- Xavfsizlik: relay faqat shu serverning o'ziga (Asterisk) ruxsat — barcha boshqa manzillar, jumladan ichki tarmoqlar (10.x, 172.16.x, 192.168.x, 127.x, 169.254.x bulut metadata) `denied-peer-ip`; `no-cli`, `no-multicast-peers`, TLS 1.0/1.1 o'chirilgan, foydalanuvchi va umumiy kvota.
- ufw: 3478 (tcp/udp), 5349/tcp, relay 49160–49760/udp (`TURN_MIN_PORT`/`TURN_MAX_PORT` bilan o'zgartiriladi).

Server NAT ortida bo'lsa routerda (yoki bulut security group'da) shu portlar va 80/443 serverga yo'naltirilishi kerak.
**Asterisk'ga TURN kerak emas** (`rtp.conf` da `turnaddr` yo'q): u serverning o'zida, TURN relay unga ichki IP orqali yetadi; ochiq IP'ni esa `stunaddr` va `PUBLIC_IP` orqali biladi. TURN faqat mijoz (brauzer/ilova) tomonidan ishlatiladi.

Tekshirish: `systemctl status coturn`, `journalctl -u coturn`; brauzerda `chrome://webrtc-internals` — qo'ng'iroq paytida tanlangan juftlikda `relay` nomzodi ko'rinadi (faqat to'g'ridan-to'g'ri yo'l bo'lmasa).

### `install.sh` nima qiladi (tartib bo'yicha)

1. Paketlar: nginx, supervisor, redis, php8.3-fpm, PostgreSQL 16 + PostGIS 3, Asterisk, certbot, ufw, fail2ban; `ibtaxi` tizim foydalanuvchisi.
2. **Darhol** firewall (ufw: SSH, 80/443, RTP 10000–20000/udp) va fail2ban (sshd, asterisk) — server uzoq bosqichlar paytida ham himoyada.
3. RAM+swap 8 GB dan kam bo'lsa — 4 GB swap. Composer, Node 22.
4. PostgreSQL: `ibtaxi` roli va bazasi, `postgis`, `pg_trgm` (parol tasodifiy; `.env` da `.env.example` dagi `ibtaxi` paroli qolgan bo'lsa — almashtiriladi). Test bazasi faqat `--dev` da.
5. Huquqlar: php-fpm `ibtaxi` nomidan ishlaydi, papka `750`. `.env` (production, baza, Reverb kalitlari) — `640`.
6. Asterisk + ODBC: `deploy/asterisk-config.sh` — `deploy/asterisk/*.conf` → `/etc/asterisk` (PUBLIC_IP, LOCAL_NET `/etc/ibtaxi/asterisk.env` da saqlanadi), DTLS sertifikati, yozuvlar papkasi, ARI paroli `.env` ga.
7. Nginx: `/` → PHP, `/app` → Reverb (8080), `/ws` → Asterisk WebSocket (8088), `/map/` → xarita tile'lari; kirish manzillari va `/ws` uchun IP bo'yicha so'rov cheklovi (`limit_req`).
8. `composer install`, `npm ci && npm run build`, `migrate`, `storage:link`, `optimize`, `telephony:sync`.
9. **`ibtaxi:init`**: rollar, bitta filial ("Asosiy filial"), superadmin `admin@<domen>` tasodifiy parol bilan (superadmin bor bo'lsa hech narsa o'zgarmaydi).
10. Let's Encrypt (`certbot --nginx --redirect`), yangilanish — certbot timer. Qayta ishga tushirilganda mavjud sertifikat nginx'ga qayta ulanadi. **Olinmasa o'rnatish to'xtamaydi** — ogohlantirish va qayta urinish buyrug'i chiqadi.
11. SIP firewall cron'i (5060 faqat trunk IP'lariga); `--turn` bo'lsa coturn (§2.1); logrotate, supervisor dasturlari, cron (`schedule:run` har daqiqada).
12. Xarita (`--skip-maps` bo'lmasa, **bir necha soat**, eng oxirida): OSM → tile'lar → OSRM (Docker, `ibtaxi-osrm`) → hududlar, mo'ljallar, ko'cha/uy raqamlari → ochiq joylar (`places-prepare.sh`: Overture Maps va Wikidata, BBOX bo'yicha; yuklanmasa o'rnatish to'xtamaydi). To'xtab qolsa ham sayt va telefoniya ishlaydi; `install.sh` ni qayta ishga tushirish tayyor qismlarni o'tkazib yuboradi.
13. Yakunda: panel manzili, SIP provayderga beriladigan IP va **superadmin login/paroli**.

### O'rnatishdan so'ng darhol

1. Yakuniy chiqishdagi **superadmin login va parolini** saqlang — ular faqat bir marta ko'rsatiladi.
   Nusxasi: `/var/www/ibtaxi/storage/app/private/first-login.txt` (faqat `ibtaxi` o'qiydi). Kirgandan keyin parolni almashtiring (Sozlamalar → Parol) va faylni o'chiring:
   `sudo rm /var/www/ibtaxi/storage/app/private/first-login.txt`.
   Parol yo'qolsa: `sudo -u ibtaxi php artisan ibtaxi:admin-password` (yangi tasodifiy parol chiqaradi).
2. Panel → Firma sozlamalari: firma nomi, standart til. Panel → Filiallar: "Asosiy filial" nomi, hududi, xarita markazi, vaqt zonasi; kerak bo'lsa boshqa filiallar.
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
| 3478/tcp+udp, 5349/tcp       | hammaga (`--turn`)      | TURN/STUN, TURN over TLS (coturn)                       |
| 49160–49760/udp              | hammaga (`--turn`)      | TURN relay portlari                                     |
| 5432, 6379, 8080, 8088, 5000 | faqat 127.0.0.1         | PostgreSQL, Redis, Reverb, Asterisk HTTP/ARI, OSRM      |

| Xizmat                           | Boshqaruv                              | Log                           |
| -------------------------------- | -------------------------------------- | ----------------------------- |
| Reverb (WebSocket)               | `supervisorctl restart ibtaxi-reverb`  | `/var/log/ibtaxi-reverb.log`  |
| Horizon (navbatlar)              | `supervisorctl restart ibtaxi-horizon` | `/var/log/ibtaxi-horizon.log` |
| ARI daemon (qo'ng'iroq mantig'i) | `php artisan ari:restart`              | `/var/log/ibtaxi-ari.log`     |
| Asterisk                         | `systemctl restart asterisk`           | `/var/log/asterisk/messages`  |
| coturn (`--turn`)                | `systemctl restart coturn`             | `journalctl -u coturn`        |
| OSRM                             | `systemctl restart ibtaxi-osrm`        | `journalctl -u ibtaxi-osrm`   |
| Laravel                          | —                                      | `storage/logs/laravel.log`    |

Rejali vazifalar (cron → `schedule:run`):

- har daqiqada: `orders:dispatch-scheduled`, `orders:rebroadcast`;
- har 5 daqiqada: `ibtaxi:health`, `drivers:release-stuck`;
- 02:30 `ibtaxi:backup`, 03:30 `recordings:cleanup`, 04:00 `locations:prune`.

Nginx so'rov cheklovi (bitta IP'dan): `/login`, `/two-factor-challenge`, `/forgot-password`, `/api/driver/v1/auth/login` — daqiqasiga 20 (+20 burst);
`/ws` (SIP over WSS) — daqiqasiga 30 yangi ulanish (+60 burst), bir vaqtda 100 tagacha. Oshsa — HTTP 429.
Asterisk WSS ulanishlarini 127.0.0.1 dan ko'radi, shuning uchun fail2ban ularni bloklamaydi — cheklov nginx'da.

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
sudo -u ibtaxi php artisan ibtaxi:update            # oxirgi versiya
sudo -u ibtaxi php artisan ibtaxi:update --ref=v1.2 # aniq teg
sudo -u ibtaxi php artisan ibtaxi:update --rollback # muammo bo'lsa — oldingi kod va baza
```

- **Har doim `sudo -u ibtaxi` bilan.** root nomidan buyruq ishlamaydi (git "dubious ownership" va fayllar root'ga o'tib qolardi).
- Yangilash: zaxira (`*_before-update.dump`) → texnik rejim → `git pull` → composer, build, migratsiya → kesh, Horizon, Reverb, `telephony:sync` → sayt ochiladi → ARI daemon qayta ishga tushadi.
- **Xato bo'lsa** (composer, npm yoki migratsiya): kod avtomatik oldingi commit'ga qaytariladi, uning bog'liqliklari qayta o'rnatiladi va sayt ochiladi. Migratsiya qisman bajarilgan bo'lishi mumkin — kerak bo'lsa `--rollback`. Qaytarishning o'zi ham muvaffaqiyatsiz bo'lsa, sayt texnik rejimda qoladi (yangi kod eski bog'liqliklar bilan ishlamasin).
- ARI daemon faol qo'ng'iroqlar tugashini kutadi (standart 10 daqiqagacha, `--wait-calls=<soniya>`), keyin qayta ishga tushadi. Sayt bu paytda ochiq. Baribir kechasi yangilagan ma'qul.
- `--ref=<teg>` dan keyingi oddiy `ibtaxi:update` avval asosiy tarmoqqa (`main`) qaytadi.
- `--rollback` yangilanishdan keyin yozilgan ma'lumotlarni (buyurtmalar, to'lovlar) o'chiradi — faqat yangilanishdan keyin darhol ishlating. Oxirgi yangilanish zaxirasi keyingi yangilanishgacha o'chirilmaydi (14 kunlik tozalashdan qat'i nazar).
- `deploy/asterisk/*.conf` o'zgargan bo'lsa, `ibtaxi:update` oxirida buyruqni ko'rsatadi (root kerak):
  `sudo /var/www/ibtaxi/deploy/asterisk-config.sh` (`core reload`, qo'ng'iroqlar uzilmaydi; to'liq qayta ishga tushirish — `--restart`).

---

## 7. Zaxira va tiklash

- Har kuni 02:30 `ibtaxi:backup`: baza (`*.dump`) + fayllar va `.env` (`*.files.tar.gz`) → `storage/app/private/backups`, 14 kun saqlanadi.
- **Suhbat yozuvlari kunlik arxivga kirmaydi** (oyiga GB'lab o'sadi). Zaxira SFTP sozlangan bo'lsa, har kuni faqat **kechagi kun** yozuvlari alohida arxiv (`<sana>_recordings.tar`) bo'lib SFTP'ga yuboriladi va mahalliy nusxasi o'chiriladi (yozuvlar serverda baribir bor). Yuborilmay qolganlari keyingi kuni qayta yuboriladi.
  SFTP keyinroq sozlansa, eski yozuvlarni bir marta yuborish: `sudo -u ibtaxi php artisan ibtaxi:backup --recordings-from=2026-01-01`.
- **Zaxira serverning o'zida turadi** — disk buzilsa yo'qoladi. Panel → Integratsiyalar → zaxira SFTP'ni albatta sozlang.

Boshqa serverga (yoki halokatdan keyin) tiklash:

```bash
# 1. Yangi serverga install.sh (yuqoridagidek), zaxira fayllarini serverga ko'chirish, keyin:
sudo -u ibtaxi php artisan ibtaxi:restore /path/<sana>_daily.dump \
    --files=/path/<sana>_daily.files.tar.gz \
    --files=/path/<sana>_recordings.tar      # yozuvlar arxivlari (ixtiyoriy, bir nechta)
```

`ibtaxi:restore` tasdiq so'raydi, so'ng: texnik rejim → `pg_restore --clean --if-exists --no-owner` → fayllar (`storage/app/private`) →
`optimize`, `telephony:sync`, Horizon/Reverb/ARI qayta ishga tushirish → sayt ochiladi.
Yangi serverning `.env` i saqlanadi (baza paroli, domen boshqa), faqat **APP_KEY** zaxiradagisiga almashtiriladi — integratsiya kalitlari shu bilan shifrlangan. Eski `.env` to'liq nusxasi `.env.restored` ga tushadi (taqqoslash uchun).
Tiklangan bazada eski superadmin bo'ladi — `ibtaxi:init` yaratgan login ishlamay qoladi.

### Xarita ma'lumotlari (boshqa serverga ko'chirishda yo'qolmasligi uchun)

Xarita ikki joyda turadi:

| Nima                                                                                          | Qayerda                          | Zaxirasi            |
| --------------------------------------------------------------------------------------------- | -------------------------------- | ------------------- |
| Mo'ljallar, ko'chalar, manzillar, hududlar; admin tuzatgan nomlar va operator qo'shgan joylar | baza                             | har kuni (`*.dump`) |
| Xarita tile'lari va shriftlar                                                                 | `public/map` (~200 MB)           | **xarita to'plami** |
| OSM eksportlari                                                                               | `storage/app/osm` (~350 MB)      | **xarita to'plami** |
| OSRM marshrut ma'lumoti                                                                       | `/var/lib/ibtaxi/osrm` (~350 MB) | **xarita to'plami** |

Xarita to'plami — `php artisan ibtaxi:map-export` (har yakshanba 03:00 avtomatik, SFTP sozlangan bo'lsa u yerga ham yuboriladi; mahalliy faqat oxirgisi saqlanadi):

```bash
sudo -u ibtaxi php artisan ibtaxi:map-export --upload     # qo'lda: storage/app/private/backups/<sana>_map.tar
```

Yangi serverga ko'chirish — xarita internetdan qayta yasalmaydi (bir necha soat va Protomaps/Geofabrik'ga bog'liqlik yo'q):

```bash
# eski serverdan <sana>_map.tar, <sana>_daily.dump, <sana>_daily.files.tar.gz ni yangi serverga ko'chiring
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --map-bundle=/root/<sana>_map.tar
sudo -u ibtaxi php artisan ibtaxi:restore /root/<sana>_daily.dump --files=/root/<sana>_daily.files.tar.gz
```

Ochiq joylarni yangilash (mavjud serverda ham): `sudo ./deploy/places-prepare.sh`, so'ng `php artisan landmarks:import --source=overture` va `--source=wikidata`. OSM'dagi mo'ljaldan 150 m ichidagi o'xshash nomlilar takror sifatida o'tkazib yuboriladi; Overture litsenziya eslatmasi `storage/app/osm/places-overture.NOTICE.txt` (to'plam bilan birga tarqatiladi).

To'plamni o'rnatilgan serverga alohida tiklash: `sudo ./deploy/map-restore.sh /root/<sana>_map.tar` (tile'lar, OSM fayllari, OSRM ma'lumoti va `ibtaxi-osrm` xizmati).

> Xarita fayllari va mo'ljallar faqat **ochiq manbalardan** (OpenStreetMap, Protomaps; mo'ljallar uchun Overture Maps places — CDLA-Permissive-2.0/Apache-2.0/CC0, Wikidata — CC0, qaror #60) yoki firmaning o'z xodimlari kiritgan ma'lumotdan bo'lishi kerak.
> Yandex, Google, 2GIS kabi xaritalardan nom yoki koordinata ko'chirilgan bo'lsa — bu mualliflik huquqini buzadi; bunday ma'lumot to'plamga ham, firmaga sotiladigan serverga ham tushmasligi kerak.

---

## 8. Ma'lum muammolar va yechimlar

| Belgi                                         | Sabab                                                              | Yechim                                                                                                                                                                                                       |
| --------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| "Sertifikat olinmadi" ogohlantirishi          | DNS hali serverga ko'rsatmaydi yoki Let's Encrypt limiti           | DNS'ni tekshirib, `sudo certbot --nginx -d <domen> -m <email> --agree-tos --redirect` yoki `install.sh` ni qayta ishga tushiring (xavfsiz). Qolgan o'rnatish (firewall, supervisor, cron) baribir bajarilgan |
| Superadmin paroli yo'qoldi                    | —                                                                  | `sudo -u ibtaxi php artisan ibtaxi:admin-password [email]`                                                                                                                                                   |
| Login sahifasida "Administrator hali yo'q"    | `ibtaxi:init` ishga tushmagan                                      | `sudo -u ibtaxi php artisan ibtaxi:init --company="..."`                                                                                                                                                     |
| Ko'p operator bir IP'dan, `/ws` 429           | ofis NAT'i ortida juda ko'p ulanish                                | `/etc/nginx/sites-available/ibtaxi` da `limit_req`/`limit_conn` qiymatlarini oshiring, `nginx -s reload`                                                                                                     |
| Mikrofon ishlamaydi                           | sahifa `http://` orqali ochilgan                                   | faqat `https://`                                                                                                                                                                                             |
| Ovoz bir tomonlama                            | server NAT ortida, ochiq IP yo'q                                   | ochiq IP'li server yoki TURN (§2.1); yoki `LOCAL_NET` noto'g'ri — `sudo LOCAL_NET=10.0.0.0/8 /var/www/ibtaxi/deploy/asterisk-config.sh --restart`                                                            |
| Ba'zi operatorlarda (ofis tarmog'i) ovoz yo'q | tarmoq UDP'ni yopadi                                               | TURN: `sudo DOMAIN=<domen> /var/www/ibtaxi/deploy/turn-config.sh`; webfonni qayta yuklash                                                                                                                    |
| Trunk "Unregistered"                          | login/parol, provayder IP'si                                       | Panel → Telefoniya; `asterisk -rx 'pjsip show registrations'`; `ufw status`                                                                                                                                  |
| Kiruvchi qo'ng'iroq kelmaydi                  | 5060 trunk IP'siga ochilmagan                                      | `sudo /var/www/ibtaxi/deploy/sip-firewall.sh`, `journalctl -t ibtaxi-sip`                                                                                                                                    |
| Telefoniya sozlamalari ta'sir qilmaydi        | ARI daemon eski sozlamani ushlab turadi                            | `sudo -u ibtaxi php artisan ari:restart`                                                                                                                                                                     |
| Operator "band" bo'lib qoldi                  | qo'ng'iroq holati osilib qolgan (Asterisk qayta ishga tushgan)     | `ari:restart`                                                                                                                                                                                                |
| Haydovchiga push kelmaydi                     | Firebase kaliti yo'q yoki `google-services.json` boshqa loyihaniki | Integratsiyalar va Android build'ni tekshiring                                                                                                                                                               |
| Xarita bo'sh                                  | tile'lar tayyorlanmagan (`--skip-maps`)                            | `deploy/osm-prepare.sh`, `map-prepare.sh`, `osrm-prepare.sh`, so'ng `regions:import`                                                                                                                         |

---

## 9. Lokal ishlab chiqish (faqat dasturchi uchun)

- `sudo ./deploy/install.sh --dev` — Multipass VM (`ibtaxi`, 192.168.252.2); self-signed https. `ibtaxi:init` ham chaqiriladi (dev bazada superadmin bo'lsa o'zgarmaydi).
- Haqiqiy telefonda sinov: Mac'da `python3 deploy/dev-lan-proxy.py` (80/443 va RTP portlarini VM'ga uzatadi, Asterisk'ni Mac IP'siga sozlaydi), ilova `-Pfirm=lan` bilan.
- Bu skript va `--dev` rejimi firma serverida **ishlatilmaydi**.
