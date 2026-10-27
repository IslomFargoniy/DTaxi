# Dtaxi — firma serveriga o'rnatish

Har bir taksi firmasi o'z serverida alohida nusxa oladi. Firmaga xos hech narsa kodda yo'q: kalitlar (SIP trunk, Click, Payme, SpeechKit, Firebase) o'rnatilgandan keyin panelda kiritiladi.

---

## 0. Tezkor o'rnatish — Mac'dan bitta buyruq

Birinchi haqiqiy o'rnatish (demo `taxi.simulator.uz`, 2026-10-24) tajribasidan. Qo'lda bajarilgan hamma qadamlar `deploy/remote-install.sh` ga kiritilgan.

**Kerak:** server IP'si, SSH foydalanuvchisi (root yoki parolsiz sudo) va paroli/kaliti, domen (A yozuvi shu IP'ga), sertifikat uchun e-mail, firma nomi, demo yoki firma serveri ekani.

```bash
# Mac'da, loyiha papkasida. Xarita demo serverdan ko'chiriladi (tile, OSRM, tuzatilgan nomlar — qayta yasalmaydi)
DTAXI_SSH_PASSWORD='<server paroli>' DTAXI_MAP_SSH_PASSWORD='<demo paroli>' \
deploy/remote-install.sh root@<IP> --domain=taxi.firma.uz --email=admin@firma.uz --company="Firma taksi" \
    --map-from=taxi@193.181.218.203            # demo server — xarita manbai
#   + --demo                                   demo server bo'lsa (har rolga foydalanuvchi, parol 11221122)

# uzilsa — xuddi shu buyruqni qayta bering; faqat kuzatish:
deploy/remote-install.sh root@<IP> --follow
```

Skript nima qiladi:

1. Server hajmini ko'rsatadi; **2 vCPU / 4 GB / 25 GB** dan kichik bo'lsa to'xtab so'raydi. Kattalashtirish kerak bo'lsa — **o'rnatishdan oldin**: hosting resurs qo'shganda serverni qayta yuklaydi va o'rnatish uziladi.
2. Domen DNS'i server IP'siga ko'rsatishini tekshiradi (aks holda SSL olinmaydi).
3. `git` (toza Ubuntu'da yo'q), `dtaxi` foydalanuvchisi va uning SSH kaliti. **Kalitni chiqaradi va kutadi** — siz uni GitHub → DTaxi → Settings → Deploy keys ga qo'shasiz (yozish huquqisiz). Keyingi yangilanishlar (`dtaxi:update`) shu kalit orqali.
4. `/var/www/dtaxi` ga `dtaxi` nomidan `git clone`.
5. `--map-from`: demo serverda `dtaxi:map-export` (~2.3 GB), to'plam tasodifiy manzilda vaqtincha ochiladi, **yangi server uni demo'dan to'g'ridan-to'g'ri yuklaydi** (Mac'ning sekin interneti orqali o'tmaydi), SHA-256 tekshiriladi, manzil o'chiriladi.
6. `install.sh --prod ...` fonda (`setsid` — SSH uzilsa ham davom etadi), log `/var/log/dtaxi-install.log` (qayta yuklanishda ham qoladi). Bosqichlar shu terminalda chiqib turadi; oxirida superadmin login/paroli.

Vaqt (2 vCPU / 4 GB): to'plam bilan ~30–40 daqiqa; to'plamsiz (butun O'zbekiston OSM → tile → OSRM → importlar) ~2–3 soat.

So'ng: haydovchi ilovasi (§4: `firms/<firma>`, `:driver:assembleRelease`, `deploy/upload-apk.sh`), panelda trunk va integratsiyalar (§2 "O'rnatishdan so'ng").

**Demo server** — `taxi.simulator.uz` (`193.181.218.203`, foydalanuvchi `taxi`, 2 vCPU / 4 GB / 24 GB): `install.sh --demo`, xarita manbai (tuzatilgan nomlar shu yerda yuritiladi), ilova `android/firms/demo`.

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

| Nima                                                                    | Qayerga kiritiladi                      | Majburiymi                   |
| ----------------------------------------------------------------------- | --------------------------------------- | ---------------------------- |
| Domen, admin e-mail (sertifikat uchun)                                  | `install.sh` parametrlari               | ha                           |
| Firma (xizmat) nomi                                                     | `--company=` yoki keyin Panel → Firma   | yo'q (standart — Dtaxi)      |
| SIP trunk: provayder (Sarkor va h.k.), login, parol, server, raqam(lar) | Panel → Telefoniya                      | qo'ng'iroqlar uchun          |
| Firebase loyihasi (`google-services.json` + server kaliti)              | Android build + Panel → Integratsiyalar | push uchun                   |
| Click / Payme kalitlari                                                 | Panel → Integratsiyalar                 | onlayn to'ldirish uchun      |
| Yandex SpeechKit kaliti                                                 | Panel → Integratsiyalar                 | IVR ovozli iboralar uchun    |
| Zaxira uchun SFTP server                                                | Panel → Integratsiyalar                 | tavsiya etiladi              |
| Xizmat hududi (viloyatlar)                                              | `BBOX` o'zgaruvchisi                    | standart — butun O'zbekiston |

---

## 2. O'rnatish

```bash
# root sifatida
apt-get update && apt-get install -y git
git clone git@github.com:IslomFargoniy/DTaxi.git /var/www/dtaxi
cd /var/www/dtaxi
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --company="Ibrat taksi"
```

Parametrlar:

| Parametr / o'zgaruvchi      | Ma'nosi                                                                                                    |
| --------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `--prod --domain= --email=` | firma serveri (majburiy uchtasi birga)                                                                     |
| `--company=`                | firma (xizmat) nomi — faqat birinchi o'rnatishda (standart `Dtaxi`)                                        |
| `--skip-maps`               | xarita/OSRM tayyorlashni o'tkazib yuborish (keyin qo'lda)                                                  |
| `--skip-ssl`                | sertifikatsiz — **faqat sinov uchun** (mikrofon https'siz ishlamaydi)                                      |
| `--turn`                    | ixtiyoriy TURN server (coturn) — §2.1                                                                      |
| `--demo`                    | demo: `admin`, `branch`, `operator`, `cashier` va haydovchi `+998900000001` — parol `11221122` (qaror #70) |
| `--map-bundle=`             | xarita to'plami (`dtaxi:map-export`) — tile, OSRM, bazadagi xarita (§7)                                    |
| `--map-dump=`               | faqat bazadagi xarita (`dtaxi:map-export --db-only`, ~15 MB) — tile/OSRM shu yerda yasaladi                |
| `BBOX=lon1,lat1,lon2,lat2`  | xizmat hududi (standart — butun O'zbekiston)                                                               |
| `PUBLIC_IP=`                | avtomatik aniqlanmasa                                                                                      |
| `LOCAL_NET=`                | server ichki tarmog'i (standart — asosiy interfeys tarmog'idan avtomatik)                                  |

Git uchun: serverda `dtaxi` foydalanuvchisiga **faqat o'qish huquqli deploy key** qo'shing (GitHub → Settings → Deploy keys), keyingi yangilanishlar shu orqali keladi. `deploy/remote-install.sh` (§0) buni o'zi tayyorlaydi.

### 2.1. TURN server (ixtiyoriy, `--turn`)

WebRTC ovozi (operator brauzeri, haydovchi va mijoz ilovalarining qo'ng'iroq sahifasi) odatda to'g'ridan-to'g'ri Asterisk'ning RTP portlariga (UDP 10000–20000) boradi. Quyidagi hollarda bu ishlamaydi va ovoz yo'q yoki bir tomonlama bo'ladi — shunda TURN yoqiladi:

- **server NAT ortida** (bulut VPS 10.x ichki IP bilan, ofisdagi server router ortida);
- operator **korporativ tarmoqda** (UDP yopiq, faqat 443/TCP ochiq) yoki haydovchi/mijozning mobil operatori UDP'ni cheklaydi. Mijoz ilovasidan qo'ng'iroq (qaror #72) yoqilgan bo'lsa TURN deyarli majburiy — mijozlar turli mobil tarmoqlarda.

```bash
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --turn
# yoki o'rnatilgan serverda alohida (qayta ishga tushirish xavfsiz):
sudo DOMAIN=taxi.firma.uz /var/www/dtaxi/deploy/turn-config.sh
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

1. Paketlar: nginx, supervisor, redis, php8.3-fpm, PostgreSQL 16 + PostGIS 3, Asterisk, certbot, ufw, fail2ban; `dtaxi` tizim foydalanuvchisi.
2. **Darhol** firewall (ufw: SSH, 80/443, RTP 10000–20000/udp) va fail2ban (sshd, asterisk) — server uzoq bosqichlar paytida ham himoyada.
3. RAM+swap 8 GB dan kam bo'lsa — 4 GB swap. Composer, Node 22.
4. PostgreSQL: `dtaxi` roli va bazasi, `postgis`, `pg_trgm` (parol tasodifiy; `.env` da `.env.example` dagi `dtaxi` paroli qolgan bo'lsa — almashtiriladi). Test bazasi va rolga `CREATEDB` faqat `--dev` da.
5. Huquqlar: php-fpm `dtaxi` nomidan ishlaydi, papka `750` (guruhda `www-data` — `public/` uchun, `asterisk` — ovozli bo'laklar uchun). `.env` (production, `LOG_LEVEL=warning`, baza, Reverb kalitlari) — `600`; `bootstrap/cache` (config keshi — barcha parollar), `storage/framework`, `storage/logs` — `700`.
6. Asterisk + ODBC: `deploy/asterisk-config.sh` — `deploy/asterisk/*.conf` → `/etc/asterisk` (PUBLIC_IP, LOCAL_NET `/etc/dtaxi/asterisk.env` da saqlanadi), DTLS sertifikati, yozuvlar papkasi, ARI paroli `.env` ga.
7. Nginx: `/` → PHP, `/app` → Reverb (8080), `/ws` → Asterisk WebSocket (8088), `/map/` → xarita tile'lari; kirish manzillari va `/ws` uchun IP bo'yicha so'rov cheklovi (`limit_req`).
8. `composer install`, `npm ci && npm run build`, `migrate`, `storage:link`, `optimize`, `telephony:sync`.
9. **`dtaxi:init`**: rollar, bitta filial ("Asosiy filial"), superadmin `admin@<domen>` tasodifiy parol bilan (superadmin bor bo'lsa hech narsa o'zgarmaydi).
10. Let's Encrypt (`certbot --nginx --redirect`), yangilanish — certbot timer. Qayta ishga tushirilganda mavjud sertifikat nginx'ga qayta ulanadi. **Olinmasa o'rnatish to'xtamaydi** — ogohlantirish va qayta urinish buyrug'i chiqadi.
11. SIP firewall cron'i (5060 faqat trunk IP'lariga; trunk host nomi DNS'da topilmasa eski qoidalar qoladi); `--turn` bo'lsa coturn (§2.1); logrotate, supervisor dasturlari, cron (`schedule:run` har daqiqada); `/etc/sudoers.d/dtaxi-supervisor` — `dtaxi` foydalanuvchisi faqat `dtaxi-horizon` va `dtaxi-ari` ni start/stop qila oladi (`dtaxi:update` uchun).
12. `--map-bundle=` bo'lsa — xarita to'plami (§7): fayllar va, `map.dump` bo'lsa, bazadagi xarita (migratsiya va `dtaxi:init` dan keyin).
13. Xarita (`--skip-maps` bo'lmasa, **bir necha soat**, eng oxirida; to'plam bilan kelgan qismlar o'tkazib yuboriladi, `map.dump` bo'lsa OSM va ochiq joylar importi ham): OSM → tile'lar → OSRM (Docker, `dtaxi-osrm`) → hududlar, mo'ljallar, ko'cha/uy raqamlari → ochiq joylar (`places-prepare.sh`: Overture Maps, Wikidata va GeoNames, BBOX bo'yicha; yuklanmasa o'rnatish to'xtamaydi). To'xtab qolsa ham sayt va telefoniya ishlaydi; `install.sh` ni qayta ishga tushirish tayyor qismlarni o'tkazib yuboradi.
14. Yakunda: panel manzili, SIP provayderga beriladigan IP va **superadmin login/paroli**.

### O'rnatishdan so'ng darhol

1. Yakuniy chiqishdagi **superadmin login va parolini** saqlang — ular faqat bir marta ko'rsatiladi.
   Nusxasi: `/var/www/dtaxi/storage/app/private/first-login.txt` (faqat `dtaxi` o'qiydi). Kirgandan keyin parolni almashtiring (Sozlamalar → Parol) va faylni o'chiring:
   `sudo rm /var/www/dtaxi/storage/app/private/first-login.txt`.
   Parol yo'qolsa: `sudo -u dtaxi php artisan dtaxi:admin-password` (yangi tasodifiy parol chiqaradi).
2. Panel → Firma sozlamalari: firma nomi, standart til. Panel → Filiallar: "Asosiy filial" nomi, hududi, xarita markazi, vaqt zonasi; kerak bo'lsa boshqa filiallar.
3. Panel → Telefoniya: SIP trunk va raqamlar → saqlash. 5 daqiqa ichida firewall trunk IP'lariga 5060 ni ochadi.
4. Panel → Integratsiyalar: Firebase, Click/Payme, SpeechKit, zaxira SFTP.
5. Xodimlar (operator, kassir) va haydovchilar.
6. Tekshiruv (§5).

---

## 3. Portlar va xizmatlar

| Port                         | Kimga ochiq             | Nima                                                                                                             |
| ---------------------------- | ----------------------- | ---------------------------------------------------------------------------------------------------------------- |
| 22/tcp                       | hammaga (fail2ban)      | SSH                                                                                                              |
| 80, 443/tcp                  | hammaga                 | panel, API, WSS (Reverb, Asterisk)                                                                               |
| 10000–20000/udp              | hammaga                 | ovoz (RTP) — brauzer, ilova, trunk                                                                               |
| 5060/udp                     | **faqat trunk IP'lari** | SIP trunk (`/usr/local/sbin/dtaxi-sip-firewall` — `deploy/sip-firewall.sh` ning root nusxasi, cron har 5 daqiqa) |
| 3478/tcp+udp, 5349/tcp       | hammaga (`--turn`)      | TURN/STUN, TURN over TLS (coturn)                                                                                |
| 49160–49760/udp              | hammaga (`--turn`)      | TURN relay portlari                                                                                              |
| 5432, 6379, 8080, 8088, 5000 | faqat 127.0.0.1         | PostgreSQL, Redis, Reverb, Asterisk HTTP/ARI, OSRM                                                               |

| Xizmat                           | Boshqaruv                             | Log                          |
| -------------------------------- | ------------------------------------- | ---------------------------- |
| Reverb (WebSocket)               | `supervisorctl restart dtaxi-reverb`  | `/var/log/dtaxi-reverb.log`  |
| Horizon (navbatlar)              | `supervisorctl restart dtaxi-horizon` | `/var/log/dtaxi-horizon.log` |
| ARI daemon (qo'ng'iroq mantig'i) | `php artisan ari:restart`             | `/var/log/dtaxi-ari.log`     |
| Asterisk                         | `systemctl restart asterisk`          | `/var/log/asterisk/messages` |
| coturn (`--turn`)                | `systemctl restart coturn`            | `journalctl -u coturn`       |
| OSRM                             | `systemctl restart dtaxi-osrm`        | `journalctl -u dtaxi-osrm`   |
| Laravel                          | —                                     | `storage/logs/laravel.log`   |

Rejali vazifalar (cron → `schedule:run`):

- har daqiqada: `orders:dispatch-scheduled`, `orders:rebroadcast`;
- har 5 daqiqada: `dtaxi:health`, `drivers:release-stuck`;
- 02:30 `dtaxi:backup`, 03:00 `drivers:balance-check`, 03:30 `recordings:cleanup`, 04:00 `locations:prune`; yakshanba 03:00 `dtaxi:map-export --upload`.

Vaqtlar — **Toshkent vaqti** (`config/app.php` → `schedule_timezone`; baza va ilova UTC'da). Zaxira va xarita eksporti fonda ishlaydi — har daqiqalik vazifalar to'xtamaydi. Har vazifaning qulfi (`withoutOverlapping`) muddatli: jarayon o'lib qolsa ham vazifa keyingi safar ishlaydi.

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
JAVA_HOME=~/Library/Java/JavaVirtualMachines/jdk-17*/Contents/Home ./gradlew :driver:assembleRelease -Pfirm=<firma>
# natija: public/android/dtaxi.<versiya>.apk + public/android/version.json
cd .. && deploy/upload-apk.sh root@taxi.firma.uz      # parol bilan: DTAXI_SSH_PASSWORD='...' deploy/upload-apk.sh ...
```

`upload-apk.sh` APK'ni 4 MB bo'laklab yuboradi, har bo'lak va butun fayl SHA-256 bilan tekshiriladi, faqat shundan keyin `version.json` almashadi. Serverda faqat oxirgi versiya qoladi — eskilari joylangandan keyin o'chiriladi. Oddiy `scp`/`rsync` sekin yoki uziladigan tarmoqda (demo'da shunday bo'ldi) **yarim APK'ni joylab qo'yardi** — haydovchilarga buzuq yangilanish taklif qilinadi. Qayta ishga tushirilsa tayyor bo'laklar qayta yuborilmaydi. Mac'dagi `rsync` (openrsync) `--append-verify` ni bilmaydi, serverda `rsync` umuman bo'lmasligi mumkin.

- **`keystore.jks` va parollarni yo'qotmang va git'ga qo'shmang.** Yo'qolsa, haydovchilar ilovani o'chirib qayta o'rnatishi kerak bo'ladi (yangilanish o'rnatilmaydi).
- Ilova `version.json` ni **ochilganda** va ochiq turganda har 30 daqiqada o'qib, yangi versiyani o'zi taklif qiladi. Versiya — `1.0.<git commit'lar soni>`: yangi APK uchun avval commit.
- Birinchi o'rnatish: haydovchi `https://taxi.firma.uz/android/dtaxi.<versiya>.apk` dan yuklaydi va "noma'lum manbalardan o'rnatish"ga ruxsat beradi.
- Telefonda: joylashuv ruxsati — "Har doim", batareya tejash — o'chirilgan (ilova sozlamalarida tugma bor).

### 4.1. Mijoz ilovasi (qaror #71) — Google Play

Haydovchi ilovasidan alohida ilova (`uz.dtaxi.client.<firma>`), shu `firms/<firma>/` papkasidan yig'iladi. Mijozlar APK o'rnatmaydi — **Google Play** orqali, firmaning o'z akkauntidan.

```bash
# firm.properties ga (ixtiyoriy — standart: applicationId dagi .driver → .client, nom — appName):
#   clientApplicationId=uz.ibrattaxi.client
#   clientAppName=Ibrat taksi
#   clientFirebaseApplicationId=1:...:android:...   # push uchun (pastda)
cd android
./gradlew :client:bundleRelease -Pfirm=<firma>     # → client/build/outputs/bundle/release/client-release.aab (Play'ga)
./gradlew :client:assembleRelease -Pfirm=<firma>   # sinov APK → public/android/client/
cd .. && deploy/upload-apk.sh root@<server> --client   # sinov APK serverga: https://<domen>/android/client/dtaxi-client.<versiya>.apk
```

**Firebase (push):** firmaning Firebase loyihasiga ikkinchi Android ilova qo'shiladi (paket — `clientApplicationId`), uning `mobilesdk_app_id` → `clientFirebaseApplicationId`. Server kaliti (service account) haydovchi ilovasi bilan bir xil — Panel → Integratsiyalar. Sozlanmasa push o'chiq, holat ilova ochiq paytida Reverb orqali keladi.

**Kirish kodi** avtomatik qo'ng'iroqda aytiladi (SMS yo'q, qaror #4): SIP trunk va Panel → Ovozlar → "yetishmayotganlarini generatsiya qilish" (iboralar `login-code`, `code-repeat`) kerak. Trunk yo'q demo serverda — `.env` da `CLIENT_TEST_CODE=1122`: faqat `DTAXI_DEMO=true` (`install.sh --demo` yozadi) va faqat test raqamlariga +998900000001…09 (qaror #73); firma serverida ishlamaydi. Kod qo'ng'iroqlari firma bo'yicha soatlik limit bilan (Panel → Telefoniya).

**Google Play Console** (firma akkaunti, bir martalik $25):

1. Ilova yaratish → "Play App Signing" — `keystore.jks` yuklash kaliti sifatida (haydovchi ilovasi bilan bir xil fayl).
2. `client-release.aab` → Internal testing → Production.
3. Maxfiylik siyosati: `https://<domen>/privacy` (sahifa tayyor, firma nomi va raqami bilan).
4. Hisobni o'chirish: ilovada Sozlamalar → "Hisobni o'chirish"; veb-havola sifatida ham `https://<domen>/privacy` (bo'limida yo'riqnoma).
5. Data safety: telefon raqami, ism, taxminiy va aniq joylashuv (faqat ilova ochiq paytida, fon yo'q), buyurtmalar tarixi — "ilova ishlashi uchun", uchinchi tomonga berilmaydi, shifrlangan uzatish (HTTPS), o'chirish so'rovi mumkin.
6. Joylashuv ruxsati — faqat "foreground" (fon joylashuvi yo'q), alohida deklaratsiya shart emas.

---

## 5. Tekshiruv ro'yxati

```bash
sudo supervisorctl status              # dtaxi-reverb, dtaxi-horizon, dtaxi-ari — RUNNING
sudo -u dtaxi php artisan dtaxi:health
asterisk -rx 'pjsip show registrations' # trunk — Registered
asterisk -rx 'pjsip show contacts'      # operatorlar/haydovchilar ulangan
ufw status | grep dtaxi-sip            # trunk IP'lari 5060 uchun ochiq
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
- [ ] `dtaxi:backup` qo'lda ishga tushirib, zaxira SFTP'ga borganini tekshirish.

---

## 6. Yangilash va qaytarish

```bash
cd /var/www/dtaxi
sudo -u dtaxi php artisan dtaxi:update            # oxirgi versiya
sudo -u dtaxi php artisan dtaxi:update --ref=v1.2 # aniq teg
sudo -u dtaxi php artisan dtaxi:update --rollback # muammo bo'lsa — oldingi kod va baza
```

- **Har doim `sudo -u dtaxi` bilan.** root nomidan buyruq ishlamaydi (git "dubious ownership" va fayllar root'ga o'tib qolardi).
- Yangilash: texnik rejim → zaxira (`*_before-update.dump`; zaxira olinmasa yangilanish boshlanmaydi, sayt ochiladi) → `git pull` → composer, build → Horizon to'xtaydi, faol qo'ng'iroqlar kutiladi, ARI daemon to'xtaydi → migratsiya → Horizon va ARI ishga tushadi → kesh, Reverb, `telephony:sync` → sayt ochiladi. Texnik rejim zaxiradan oldin — zaxiradan keyin tushgan buyurtmalar `--rollback` da yo'qolmaydi.
- **Xato bo'lsa** (composer, npm yoki migratsiya): kod avtomatik oldingi commit'ga qaytariladi, uning bog'liqliklari qayta o'rnatiladi va sayt ochiladi. Migratsiya qisman bajarilgan bo'lishi mumkin — kerak bo'lsa `--rollback`. Qaytarishning o'zi ham muvaffaqiyatsiz bo'lsa, sayt texnik rejimda qoladi (yangi kod eski bog'liqliklar bilan ishlamasin).
- ARI daemon to'xtashidan oldin faol qo'ng'iroqlar tugashi kutiladi (standart 10 daqiqagacha, `--wait-calls=<soniya>`). Sayt bu paytda texnik rejimda — kechasi yangilang.
- `--rollback` ham Horizon va ARI'ni to'xtatib bazani tiklaydi. Tiklashdan oldin zaxirada yo'q jadvallar (yangi versiya migratsiyalari yaratgan) o'chiriladi — eski kod bilan keyingi `migrate` "already exists" bilan to'xtamaydi.
- Supervisor'ga ruxsat bo'lmasa (`sudoers` yozilmagan eski o'rnatish yoki dev) — Horizon va ARI to'xtatilmaydi, oxirida eski usulda qayta ishga tushadi; `install.sh` ni qayta ishga tushirish ruxsatni qo'shadi.
- `--ref=<teg>` dan keyingi oddiy `dtaxi:update` avval asosiy tarmoqqa (`main`) qaytadi.
- `--rollback` yangilanishdan keyin yozilgan ma'lumotlarni (buyurtmalar, to'lovlar) o'chiradi — faqat yangilanishdan keyin darhol ishlating. Oxirgi yangilanish zaxirasi keyingi yangilanishgacha o'chirilmaydi (14 kunlik tozalashdan qat'i nazar).
- `deploy/asterisk/*.conf` o'zgargan bo'lsa, `dtaxi:update` oxirida buyruqni ko'rsatadi (root kerak):
  `sudo /var/www/dtaxi/deploy/asterisk-config.sh` (`core reload`, qo'ng'iroqlar uzilmaydi; to'liq qayta ishga tushirish — `--restart`).

---

## 7. Zaxira va tiklash

- Har kuni 02:30 (Toshkent) `dtaxi:backup`: baza (`*.dump`) + fayllar va `.env` (`*.files.tar.gz`) → `storage/app/private/backups`, 14 kun saqlanadi (`--keep=`).
- Zaxira serverida (SFTP) ham eski nusxalar o'chiriladi: kunlik — `--keep` kundan eski, suhbat yozuvlari — yozuvlarni saqlash muddatidan (Sozlamalar, standart 90 kun) eski, xarita to'plamidan — oxirgi ikkitasi qoladi. Boshqa fayllarga tegilmaydi.
- SFTP host kaliti: integratsiyada `host_fingerprint` (masalan `ssh-keyscan -t ed25519 <host> | ssh-keygen -lf -` dagi `SHA256:...`) bo'lsa — boshqa server o'zini zaxira serveri deb ko'rsata olmaydi.
- **Suhbat yozuvlari kunlik arxivga kirmaydi** (oyiga GB'lab o'sadi). Zaxira SFTP sozlangan bo'lsa, har kuni faqat **kechagi kun** yozuvlari alohida arxiv (`<sana>_recordings.tar`) bo'lib SFTP'ga yuboriladi va mahalliy nusxasi o'chiriladi (yozuvlar serverda baribir bor). Yuborilmay qolganlari keyingi kuni qayta yuboriladi.
  SFTP keyinroq sozlansa, eski yozuvlarni bir marta yuborish: `sudo -u dtaxi php artisan dtaxi:backup --recordings-from=2026-01-01`.
- **Zaxira serverning o'zida turadi** — disk buzilsa yo'qoladi. Panel → Integratsiyalar → zaxira SFTP'ni albatta sozlang.

Boshqa serverga (yoki halokatdan keyin) tiklash:

```bash
# 1. Yangi serverga install.sh (yuqoridagidek), zaxira fayllarini serverga ko'chirish, keyin:
sudo -u dtaxi php artisan dtaxi:restore /path/<sana>_daily.dump \
    --files=/path/<sana>_daily.files.tar.gz \
    --files=/path/<sana>_recordings.tar      # yozuvlar arxivlari (ixtiyoriy, bir nechta)
```

`dtaxi:restore` tasdiq so'raydi, so'ng: texnik rejim → `pg_restore --clean --if-exists --no-owner` → fayllar (`storage/app/private`) →
`optimize`, `telephony:sync`, Horizon/Reverb/ARI qayta ishga tushirish → sayt ochiladi.
Yangi serverning `.env` i saqlanadi (baza paroli, domen boshqa), faqat **APP_KEY** zaxiradagisiga almashtiriladi — integratsiya kalitlari shu bilan shifrlangan. Eski `.env` to'liq nusxasi `.env.restored` ga tushadi (taqqoslash uchun).
Tiklangan bazada eski superadmin bo'ladi — `dtaxi:init` yaratgan login ishlamay qoladi.

### Xarita ma'lumotlari (boshqa serverga ko'chirishda yo'qolmasligi uchun)

Xarita ikki joyda turadi:

| Nima                                                                                          | Qayerda                         | Zaxirasi                                            |
| --------------------------------------------------------------------------------------------- | ------------------------------- | --------------------------------------------------- |
| Mo'ljallar, ko'chalar, manzillar, hududlar; admin tuzatgan nomlar va operator qo'shgan joylar | baza                            | har kuni (`*.dump`) va xarita to'plami (`map.dump`) |
| Xarita tile'lari va shriftlar                                                                 | `public/map` (~200 MB)          | **xarita to'plami**                                 |
| OSM eksportlari                                                                               | `storage/app/osm` (~350 MB)     | **xarita to'plami**                                 |
| OSRM marshrut ma'lumoti                                                                       | `/var/lib/dtaxi/osrm` (~350 MB) | **xarita to'plami**                                 |

Xarita to'plami — `php artisan dtaxi:map-export` (har yakshanba 03:00 avtomatik, SFTP sozlangan bo'lsa u yerga ham yuboriladi; mahalliy faqat oxirgisi saqlanadi):

```bash
sudo -u dtaxi php artisan dtaxi:map-export --upload     # qo'lda: storage/app/private/backups/<sana>_map.tar
```

To'plam ichida (qaror #65) bazadagi xarita qismi ham bor — `map.dump` (`pg_dump --data-only`, faqat `regions`, `landmarks`, `street_renames`; ID'lar saqlanadi). Buyurtma, mijoz, haydovchi, pul ma'lumotlari va foydalanuvchilar **kirmaydi**.

**Tuzatilgan xaritani yangi firmaga berish** (masalan Farg'onada ishlayotgan firmaning xaritasi shu shahardagi yangi xaridorga):

```bash
# 1. Manba serverda (tuzatilgan xarita shu yerda)
sudo -u dtaxi php artisan dtaxi:map-export            # storage/app/private/backups/<sana>_map.tar
# 2. Faylni yangi firma serveriga ko'chiring (scp), so'ng u yerda
sudo ./deploy/install.sh --prod --domain=taxi.yangifirma.uz --email=admin@yangifirma.uz --map-bundle=/root/<sana>_map.tar
```

`install.sh` migratsiya va `dtaxi:init` dan keyin `map-restore.sh` ni chaqiradi: fayllar joyiga tushadi, so'ng `dtaxi:map-import` bitta tranzaksiyada 3 jadvalni tozalab yuklaydi — `created_by` → NULL (eski firma foydalanuvchilari), `usage_count` → 0 (eski firma statistikasi), `edited_at` saqlanadi, sequence'lar yangilanadi. OSM importi (`regions:import`, `landmarks:import`) va ochiq joylar importi **o'tkazib yuboriladi** — ular o'chirilgan mo'ljallarni qaytarardi. Tahrirlangan nomlar, qo'lda qo'shilgan va o'chirilgan mo'ljallar, qayta nomlangan ko'chalar aynan manba serverdagidek bo'ladi.
Ishlab turgan serverda (filial hududi yoki mijoz manzillari mavjud xaritaga bog'langan) `dtaxi:map-import` rad etadi va hech narsa o'zgarmaydi; ataylab almashtirish — `sudo -u dtaxi php artisan dtaxi:map-import <fayl> --force` (ID'lari saqlangan bog'lanishlar tiklanadi, qolganlari bo'shatiladi). Manba va yangi server bir xil versiyada bo'lishi kerak (ustunlar mos kelmasa import rad etiladi).

**Demo serverdan yangi firmaga** (odatiy yo'l, §0): `deploy/remote-install.sh ... --map-from=taxi@193.181.218.203` — to'plam demo'da yaratiladi va yangi server uni to'g'ridan-to'g'ri yuklaydi. Faqat nomlar kerak bo'lsa (tile/OSRM yangi serverda yasalsin): manba serverda `dtaxi:map-export --db-only` → `install.sh --map-dump=<fayl>`. Lokal VM'dan: `multipass exec dtaxi -- bash -c "cd /home/ubuntu/ibTaxi && php artisan dtaxi:map-export --db-only"` (Mac'da `pg_dump` yo'q).

**Shu firmaning o'zi serverini almashtirsa** — xarita to'plami + to'liq zaxira (baza to'liq tiklanadi, `map.dump` ustidan yoziladi):

```bash
# eski serverdan <sana>_map.tar, <sana>_daily.dump, <sana>_daily.files.tar.gz ni yangi serverga ko'chiring
sudo ./deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz --map-bundle=/root/<sana>_map.tar
sudo -u dtaxi php artisan dtaxi:restore /root/<sana>_daily.dump --files=/root/<sana>_daily.files.tar.gz
```

Qayta nomlangan ko'chalar (admin → "Ko'cha nomlari") OSM importidan keyin avtomatik qayta qo'llanadi — `landmarks:import` ni qayta ishga tushirish ularni bekor qilmaydi.

Ochiq joylarni yangilash (mavjud serverda ham): `sudo ./deploy/places-prepare.sh`, so'ng `php artisan landmarks:import --source=overture`, `--source=wikidata` va `--source=geonames`. OSM'dagi mo'ljaldan 150 m ichidagi o'xshash nomlilar takror sifatida o'tkazib yuboriladi (GeoNames qishloq/mahallalari — boshqa qishloq/mahalladan 2 km ichida); litsenziya eslatmalari `storage/app/osm/places-overture.NOTICE.txt` va `places-geonames.NOTICE.txt` (to'plam bilan birga tarqatiladi). GeoNames CC-BY 4.0 — xaritadagi "GeoNames" atributsiyasini olib tashlamang (qaror #64).

To'plamni o'rnatilgan serverga alohida tiklash: `sudo ./deploy/map-restore.sh /root/<sana>_map.tar` (tile'lar, OSM fayllari, OSRM ma'lumoti va `dtaxi-osrm` xizmati; `map.dump` bo'lsa — `dtaxi:map-import`, yuqoridagi cheklov bilan).

> Xarita fayllari va mo'ljallar faqat **ochiq manbalardan** (OpenStreetMap, Protomaps; mo'ljallar uchun Overture Maps places — CDLA-Permissive-2.0/Apache-2.0/CC0, Wikidata — CC0, qaror #60; GeoNames — CC-BY 4.0, qaror #64) yoki firmaning o'z xodimlari kiritgan ma'lumotdan bo'lishi kerak.
> Yandex, Google, 2GIS kabi xaritalardan nom yoki koordinata ko'chirilgan bo'lsa — bu mualliflik huquqini buzadi; bunday ma'lumot to'plamga ham, firmaga sotiladigan serverga ham tushmasligi kerak.

---

## 8. Ma'lum muammolar va yechimlar

| Belgi                                                            | Sabab                                                                               | Yechim                                                                                                                                                                                                       |
| ---------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| "Sertifikat olinmadi" ogohlantirishi                             | DNS hali serverga ko'rsatmaydi yoki Let's Encrypt limiti                            | DNS'ni tekshirib, `sudo certbot --nginx -d <domen> -m <email> --agree-tos --redirect` yoki `install.sh` ni qayta ishga tushiring (xavfsiz). Qolgan o'rnatish (firewall, supervisor, cron) baribir bajarilgan |
| Superadmin paroli yo'qoldi                                       | —                                                                                   | `sudo -u dtaxi php artisan dtaxi:admin-password [email]`                                                                                                                                                     |
| Login sahifasida "Administrator hali yo'q"                       | `dtaxi:init` ishga tushmagan                                                        | `sudo -u dtaxi php artisan dtaxi:init --company="..."`                                                                                                                                                       |
| Ko'p operator bir IP'dan, `/ws` 429                              | ofis NAT'i ortida juda ko'p ulanish                                                 | `/etc/nginx/sites-available/dtaxi` da `limit_req`/`limit_conn` qiymatlarini oshiring, `nginx -s reload`                                                                                                      |
| Mikrofon ishlamaydi                                              | sahifa `http://` orqali ochilgan                                                    | faqat `https://`                                                                                                                                                                                             |
| Ovoz bir tomonlama                                               | server NAT ortida, ochiq IP yo'q                                                    | ochiq IP'li server yoki TURN (§2.1); yoki `LOCAL_NET` noto'g'ri — `sudo LOCAL_NET=10.0.0.0/8 /var/www/dtaxi/deploy/asterisk-config.sh --restart`                                                             |
| Ba'zi operatorlarda (ofis tarmog'i) ovoz yo'q                    | tarmoq UDP'ni yopadi                                                                | TURN: `sudo DOMAIN=<domen> /var/www/dtaxi/deploy/turn-config.sh`; webfonni qayta yuklash                                                                                                                     |
| Trunk "Unregistered"                                             | login/parol, provayder IP'si                                                        | Panel → Telefoniya; `asterisk -rx 'pjsip show registrations'`; `ufw status`                                                                                                                                  |
| Kiruvchi qo'ng'iroq kelmaydi                                     | 5060 trunk IP'siga ochilmagan                                                       | `sudo /usr/local/sbin/dtaxi-sip-firewall`, `journalctl -t dtaxi-sip`                                                                                                                                         |
| Telefoniya sozlamalari ta'sir qilmaydi                           | ARI daemon eski sozlamani ushlab turadi                                             | `sudo -u dtaxi php artisan ari:restart`                                                                                                                                                                      |
| Operator "band" bo'lib qoldi                                     | qo'ng'iroq holati osilib qolgan (Asterisk qayta ishga tushgan)                      | `ari:restart`                                                                                                                                                                                                |
| Haydovchiga push kelmaydi                                        | Firebase kaliti yo'q yoki `google-services.json` boshqa loyihaniki                  | Integratsiyalar va Android build'ni tekshiring                                                                                                                                                               |
| Xarita bo'sh                                                     | tile'lar tayyorlanmagan (`--skip-maps`)                                             | `deploy/osm-prepare.sh`, `map-prepare.sh`, `osrm-prepare.sh`, so'ng `regions:import`                                                                                                                         |
| O'rnatish o'rtada to'xtadi                                       | server qayta yuklandi (masalan hosting resurs qo'shdi), SSH uzildi                  | `install.sh` ni qayta ishga tushiring (yoki `remote-install.sh` ni) — tayyor qismlar o'tkaziladi. OSM bosqichi oxirgi fayl (`streets.geojsonseq`) bo'yicha tekshiriladi, yarim qolgani qayta yasaladi        |
| `dtaxi:map-import`: "Permission denied" (`*.sql`)                | `pg_restore` dump yonida vaqtinchalik fayl yozadi                                   | dump'ni `dtaxi` yoza oladigan papkaga qo'ying (`storage/app/private/...`), `/var/lib/dtaxi` yoki `/root` emas                                                                                                |
| Yangilashdan keyin ilovaga chat/takliflar kelmaydi               | `dtaxi:update` paytida (texnik rejim, 503) ilova kanalga obuna bo'la olmagan        | 1.0.218 dan ilova obunani o'zi qayta urinadi; eski versiyada — ilovani yopib qayta ochish                                                                                                                    |
| Operator javob berishi ~3–4 s kechikadi                          | brauzer ICE yig'ilishini oxirigacha kutardi (javob bermaydigan IPv6/VPN interfeysi) | tuzatilgan (`ef54e27`): srflx/relay nomzodi kelishi bilan javob; panelni Cmd+Shift+R                                                                                                                         |
| Chatda "Xabar yuborilmadi", konsolda `ERR_INTERNET_DISCONNECTED` | operator kompyuterining interneti uzilgan                                           | server emas — ulanishni tekshirib "Qayta urinish"                                                                                                                                                            |

---

## 9. Lokal ishlab chiqish (faqat dasturchi uchun)

- `sudo ./deploy/install.sh --dev` — Multipass VM (`dtaxi`, 192.168.252.5); self-signed https. `dtaxi:init` ham chaqiriladi (dev bazada superadmin bo'lsa o'zgarmaydi).
- Haqiqiy telefonda sinov: Mac'da `python3 deploy/dev-lan-proxy.py` (80/443 va RTP portlarini VM'ga uzatadi, Asterisk'ni Mac IP'siga sozlaydi), ilova `-Pfirm=lan` bilan.
- Bu skript va `--dev` rejimi firma serverida **ishlatilmaydi**.
