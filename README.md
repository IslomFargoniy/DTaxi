# Dtaxi

Telefon orqali ishlaydigan mahalliy taksi xizmatlari uchun dispetcherlik tizimi.

Mijoz qo'ng'iroq qiladi, bo'sh operator javob beradi va mo'ljalni kiritadi. Buyurtma "efir" orqali yaqin atrofdagi bo'sh haydovchilarga bir vaqtda ko'rsatiladi. Birinchi qabul qilgan haydovchi buyurtmani oladi. Mijozga haydovchi ma'lumotlari va "haydovchi kutmoqda" xabari avtomatik ovozli qo'ng'iroq bilan yetkaziladi.

Dtaxi taksi firmalariga sotiladigan mahsulot. Har bir firma o'z serverida alohida nusxa oladi. Tashqi xizmatlarga (SIP trunk, Click, Payme, SpeechKit, Firebase) firma o'zi shartnoma tuzadi va kalitlarini admin panelga kiritadi.

## Holat

Versiya: `VERSION` fayli, o'zgarishlar — [CHANGELOG.md](CHANGELOG.md). Rejadagi barcha dasturiy bosqichlar (0–5) tayyor;
pilot firma bilan sinov (Sarkor trunki, haqiqiy Click/Payme kalitlari, SpeechKit ovozlari, Daniya serveri) qoldi.

To'liq reja, qabul qilingan qarorlar va bosqichlar [docs/plan.md](docs/plan.md) faylida.

## Imkoniyatlar

- **Operator paneli** (veb): brauzer ichida softfon (WebRTC). Qo'ng'iroq kelganda mijoz kartochkasi avtomatik ochiladi. Ekranda xarita, mo'ljal qidiruvi va faol buyurtmalar ko'rinadi.
- **Buyurtma tarqatish**: efir orqali, radius bo'yicha. Tarif klasslari: Standart, Komfort, Yuk, Dostavka.
- **Telefoniya**: Asterisk, qo'ng'iroqlar navbati, qo'ng'iroqlarni yozib olish va avtomatik TTS qo'ng'iroqlar. PBX boshqaruv paneli tizim ichida.
- **Narx**: boshlang'ich narx (ichida N km) va har keyingi km. Kutish puli, tungi narx va shahar tashqarisi narxi panelda yoqiladi.
- **Haydovchi balansi**: har buyurtmadan komissiya yechiladi. Balans Click, Payme yoki kassa orqali to'ldiriladi.
- **Haydovchi ilovasi** (Android 10+): ilova ichida xarita va marshrut, taksometr, push xabarlar, dispetcher bilan qo'ng'iroq va chat. APK firma serveridan tarqatiladi va avtomatik yangilanadi.
- **Tillar**: o'zbek (lotin) va rus.
- **Ko'p filial**: telefon raqamlarini filiallarga panelda bog'lash mumkin. Filial hududi viloyat, shahar yoki tuman bo'yicha tanlanadi.

## Rollar

| Rol        | Vazifasi                                                     |
| ---------- | ------------------------------------------------------------ |
| superadmin | Butun tizim, filiallar, integratsiyalar                      |
| admin      | O'z filiali: haydovchilar, operatorlar, tariflar, hisobotlar |
| operator   | Qo'ng'iroq qabul qilish, buyurtma berish                     |
| kassir     | Haydovchi balansini naqd to'ldirish                          |
| driver     | Mobil ilova                                                  |

## Texnologiyalar

| Qism               | Texnologiya                                          |
| ------------------ | ---------------------------------------------------- |
| Backend            | Laravel 13, PHP 8.3+                                 |
| Panel              | React 19, Inertia 3, Tailwind 4, shadcn/ui           |
| Ma'lumotlar bazasi | PostgreSQL + PostGIS, Redis                          |
| Real vaqt          | Laravel Reverb (WebSocket), Firebase Cloud Messaging |
| Xarita             | MapLibre + OpenStreetMap (o'z serverida), OSRM       |
| Telefoniya         | Asterisk (PJSIP, WebRTC), Yandex SpeechKit (TTS)     |
| Haydovchi ilovasi  | Kotlin, Jetpack Compose                              |
| Server             | 1 ta VPS, toza Ubuntu (o'rnatish skripti)            |

## Firma serveriga o'rnatish

Toza Ubuntu 24.04 server, domen DNS'da server IP'siga yo'naltirilgan bo'lishi kerak:

```bash
sudo git clone <repo> /var/www/ibtaxi
sudo /var/www/ibtaxi/deploy/install.sh --prod --domain=taxi.firma.uz --email=admin@firma.uz
```

Skript hammasini o'rnatadi (PostgreSQL+PostGIS, Redis, Asterisk, OSRM, xarita, hududlar, HTTPS, ufw, fail2ban).
Hudud standart — butun O'zbekiston; kichikroq hudud uchun `BBOX=lon1,lat1,lon2,lat2`. Tugagach `https://<domen>` da
sozlash ustasi ochiladi (firma nomi, superadmin, birinchi filial). So'ng panelda: Integratsiyalar (Click, Payme,
SpeechKit, Firebase), Telefoniya (SIP trunk, raqamlar), Ovozli bo'laklar.

Yangilash: `php artisan ibtaxi:update` (avval zaxira oladi), muammo bo'lsa `php artisan ibtaxi:update --rollback`.
Haydovchi ilovasini firma uchun build qilish: [android/firms/README.md](android/firms/README.md).

## Lokal ishlab chiqish

Multipass virtual mashinasi (Ubuntu 24.04), kod Mac'dan ulanadi:

```bash
multipass launch 24.04 --name ibtaxi --cpus 2 --memory 4G --disk 30G
multipass mount . ibtaxi:/home/ubuntu/ibTaxi
multipass exec ibtaxi -- sudo /home/ubuntu/ibTaxi/deploy/install.sh --dev
composer install && npm install && php artisan migrate --seed
php artisan simulate:drivers    # soxta haydovchilar (sinov uchun)
```

## Tekshiruvlar

```bash
composer test       # Pint, PHPStan, testlar
npm run check       # frontend lint va format
```

## Litsenziya

Xususiy dasturiy ta'minot. Barcha huquqlar himoyalangan.
