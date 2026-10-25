# Dtaxi — loyiha qoidalari

Telefon orqali ishlaydigan taksi dispetcherlik tizimi. Mahsulot nomi — **Dtaxi** (logo: D ichida lokatsiya belgisi); kod ichida ham hamma joyda `dtaxi` (Android paketi `uz.dtaxi.driver`, `dtaxi:*` buyruqlari, baza, VM — qaror #68). Faqat Mac'dagi `ibTaxi` papkasi va VM ichidagi `/home/ubuntu/ibTaxi` mount yo'li eski nomda. **Sotiladigan mahsulot**: har bir taksi firmasi o'z serverida alohida nusxa oladi. To'liq reja va qabul qilingan qarorlar — `docs/plan.md` (o'zgartirishdan oldin o'qing, qaror qabul qilinsa jadvalga qo'shing).

## Asosiy qoidalar

- **Firmaga xos hech narsa kodda yoki `.env` da emas.** Kalitlar (Click, Payme, SpeechKit, Firebase, SIP) — `integrations` jadvalida shifrlangan; sozlamalar — `App\Support\Settings` (`Settings::DEFAULTS` ga yangi kalit qo'shiladi).
- **Filial chegarasi**: filialga tegishli modellar `App\Models\Concerns\BelongsToBranch` trait'ini ishlatadi — admin/operator/kassir faqat o'z filial(lar)ini ko'radi, superadmin hammasini.
- **Rollar**: `App\Enums\Role` (superadmin, admin, operator, cashier, driver) va ularning ruxsatlari shu enum'da; superadmin `Gate::before` orqali hamma narsaga ruxsatli.
- **Tillar**: o'zbek lotin (`uz`), o'zbek kirill (`oz`), rus (`ru`), ingliz (`en`). Frontend matnlari — inglizcha kalit + `lang/uz.json`, `lang/ru.json` (`lang/en.json` faqat kalit inglizcha matn bo'lmasa, masalan `offer.accepted`); React'da `useTrans()` → `t('Key')`. Kirill qo'lda yozilmaydi: uz o'zgargach `php artisan lang:cyrillic` (lang/oz yasaladi; test kalitlar mosligini tekshiradi). Android: values (uz), values-ru, values-en, values-b+uz+Cyrl (`android/tools/uz_cyrillic.py`).
- **Faqat Android**: haydovchi va mijoz ilovalari (qaror #71) — `android/` da `:core` (umumiy: tillar, saqlash, HTTP, Reverb, mavzu), `:driver`, `:client`; firma sozlamalari `android/buildSrc/Firm.kt` orqali. iOS yo'q. FastPanel ishlatilmaydi.
- Testlar majburiy (Pest, haqiqiy PostgreSQL+PostGIS bilan). Dispatcher, narx, balans va buyurtma statuslari — albatta testlar bilan.

## Muhit

- Lokal: Multipass virtual mashinasi `dtaxi` (Ubuntu 24.04, IP `192.168.252.5`) — PostgreSQL+PostGIS, Redis, Nginx shu yerda. Loyiha papkasi VM ichida `/home/ubuntu/ibTaxi` ga ulangan. Qayta ishga tushirish: `multipass stop dtaxi && multipass start dtaxi` (`restart` ulangan papkani uzib qo'yishi mumkin); keyin soat orqada qolsa (haydovchilar kulrang, GPS "eskirgan") — `multipass exec dtaxi -- sudo systemctl restart systemd-timesyncd`. VM qayta yaratilsa IP o'zgaradi — `.env`, shu fayl, `deploy/deploy.md`, `deploy/dev-lan-proxy.py`, `android/firms/dev/firm.properties`.
- Kod, `composer`, `npm`, `php artisan` va testlar — Mac'da (`.env` VM'dagi bazaga ulanadi).
- Server o'rnatish: `deploy/install.sh` (toza Ubuntu; `--dev` lokal VM, `--prod --domain= --email=` firma serveri). OSM ma'lumoti: `deploy/osm-prepare.sh` → `php artisan regions:import` (butun O'zbekiston, qaror #67).
- Telefoniya: Asterisk VM'da; qo'ng'iroq mantig'i `App\Services\Telephony\CallCenter` (ARI), daemon `php artisan ari:listen` (supervisor `dtaxi-ari`; yangi kod uchun `ari:restart`). Panel brauzerda `https://192.168.252.5` (self-signed) — mikrofon faqat https'da.
- Android: JDK 17 (`~/Library/Java/JavaVirtualMachines/jdk-17*`), Studio'ning JBR'i (25) Gradle 8.9 bilan ishlamaydi. Har `:driver:assembleDebug`/`:driver:assembleRelease` dan keyin haydovchi ilovasining universal APK'si `public/android/dtaxi.<versiya>.apk` ga tushadi (faqat oxirgisi, git'ga tushmaydi). Versiya — `1.0.<git commit'lar soni>`; mijoz ilovasi — o'z hisobi bilan: `1.0.<android/client va android/core ni o'zgartirgan commit'lar soni>` (APK `public/android/client/`). Haqiqiy telefon uchun: `./gradlew :driver:assembleRelease -Pfirm=lan` + Mac'da `python3 deploy/dev-lan-proxy.py`.

## Buyruqlar

```bash
php artisan test                 # testlar (dtaxi_test bazasi)
composer test                    # Pint + PHPStan + testlar
npm run check                    # frontend format va lint
npx tsc --noEmit                 # TypeScript
php artisan wayfinder:generate --with-form   # route'lar o'zgarsa
npm run build                    # panel VM orqali http://192.168.252.5 da ochiladi
php artisan telephony:sync       # ichki raqamlar va trunklarni Asterisk'ga qayta yozish
php artisan dtaxi:backup        # baza zaxirasi; dtaxi:update [--rollback] — firma serverida yangilash
```

## Kod uslubi

- Mavjud kodga moslashing: PHP atributlari (`#[Fillable]`), enum'lar, qisqa o'zbekcha izohlar faqat "nima uchun"ni tushuntirish uchun.
- PHPStan level 7 — xatolarni `@phpstan-ignore` bilan emas, to'g'ri tiplar bilan tuzating.
- Migratsiyalar faqat qo'shuvchi bo'lsin (yangilanishlar barcha firmalarga bepul yetkaziladi — reja §0.2).
