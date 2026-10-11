# ibTaxi

Telefon orqali ishlaydigan mahalliy taksi xizmatlari uchun dispetcherlik tizimi.

Mijoz qo'ng'iroq qiladi, bo'sh operator javob beradi va mo'ljalni kiritadi. Buyurtma "efir" orqali yaqin atrofdagi bo'sh haydovchilarga bir vaqtda ko'rsatiladi. Birinchi qabul qilgan haydovchi buyurtmani oladi. Mijozga haydovchi ma'lumotlari va "haydovchi kutmoqda" xabari avtomatik ovozli qo'ng'iroq bilan yetkaziladi.

ibTaxi taksi firmalariga sotiladigan mahsulot. Har bir firma o'z serverida alohida nusxa oladi. Tashqi xizmatlarga (SIP trunk, Click, Payme, SpeechKit, Firebase) firma o'zi shartnoma tuzadi va kalitlarini admin panelga kiritadi.

## Holat

Loyiha rejalashtirish bosqichida, reja tasdiqlangan. Keyingi qadam Bosqich 0: PostgreSQL + PostGIS, rollar, filiallar va integratsiyalar.

To'liq reja, qabul qilingan qarorlar va bosqichlar [docs/plan.md](docs/plan.md) faylida.

## Imkoniyatlar

- **Operator paneli** (veb): brauzer ichida softfon (WebRTC). Qo'ng'iroq kelganda mijoz kartochkasi avtomatik ochiladi. Ekranda xarita, mo'ljal qidiruvi va faol buyurtmalar ko'rinadi.
- **Buyurtma tarqatish**: efir orqali, radius bo'yicha. Tarif klasslari: Standart, Komfort, Yuk, Dostavka.
- **Telefoniya**: Asterisk, qo'ng'iroqlar navbati, qo'ng'iroqlarni yozib olish va avtomatik TTS qo'ng'iroqlar. PBX boshqaruv paneli tizim ichida.
- **Narx**: boshlang'ich narx (ichida N km) va har keyingi km. Kutish puli, tungi narx va shahar tashqarisi narxi panelda yoqiladi.
- **Haydovchi balansi**: har buyurtmadan komissiya yechiladi. Balans Click, Payme yoki kassa orqali to'ldiriladi.
- **Haydovchi ilovasi** (Android 10+): ilova ichida xarita va marshrut, taksometr, push xabarlar. APK firma serveridan tarqatiladi va avtomatik yangilanadi.
- **Ko'p filial**: telefon raqamlarini filiallarga panelda bog'lash mumkin. Filial hududi viloyat, shahar yoki tuman bo'yicha tanlanadi.

## Rollar

| Rol | Vazifasi |
|---|---|
| superadmin | Butun tizim, filiallar, integratsiyalar |
| admin | O'z filiali: haydovchilar, operatorlar, tariflar, hisobotlar |
| operator | Qo'ng'iroq qabul qilish, buyurtma berish |
| kassir | Haydovchi balansini naqd to'ldirish |
| driver | Mobil ilova |

## Texnologiyalar

| Qism | Texnologiya |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Panel | React 19, Inertia 3, Tailwind 4, shadcn/ui |
| Ma'lumotlar bazasi | PostgreSQL + PostGIS, Redis |
| Real vaqt | Laravel Reverb (WebSocket), Firebase Cloud Messaging |
| Xarita | MapLibre + OpenStreetMap (o'z serverida), OSRM |
| Telefoniya | Asterisk (PJSIP, WebRTC), Yandex SpeechKit (TTS) |
| Haydovchi ilovasi | Kotlin, Jetpack Compose |
| Server | 1 ta VPS + FastPanel |

## Lokal ishga tushirish

Kerak bo'ladi: PHP 8.3+, Composer, Node.js.

```bash
git clone git@github.com:IslomFargoniy/ibTaxi.git
cd ibTaxi
composer setup      # bog'liqliklar, .env, kalit, migratsiyalar, frontend build
composer dev        # server + Vite
```

Hozir lokal ma'lumotlar bazasi sifatida SQLite ishlatiladi. Bosqich 0 da PostgreSQL + PostGIS va Docker Compose (Redis, OSRM, Asterisk) muhitiga o'tiladi.

## Tekshiruvlar

```bash
composer test       # Pint, PHPStan, testlar
npm run check       # frontend lint va format
```

## Litsenziya

Xususiy dasturiy ta'minot. Barcha huquqlar himoyalangan.
