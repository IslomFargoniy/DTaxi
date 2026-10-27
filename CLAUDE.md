# Dtaxi — loyiha qoidalari

Telefon orqali ishlaydigan taksi dispetcherlik tizimi. Mahsulot nomi — **Dtaxi** (logo: D ichida lokatsiya belgisi); kod ichida ham hamma joyda `dtaxi` (Android paketi `uz.dtaxi.driver`, `dtaxi:*` buyruqlari, baza, VM — qaror #68). Faqat Mac'dagi `ibTaxi` papkasi eski nomda. **Sotiladigan mahsulot**: har bir taksi firmasi o'z serverida alohida nusxa oladi. To'liq reja va qabul qilingan qarorlar — `docs/plan.md` (o'zgartirishdan oldin o'qing, qaror qabul qilinsa jadvalga qo'shing).

## Asosiy qoidalar

- **Firmaga xos hech narsa kodda yoki `.env` da emas.** Kalitlar (Click, Payme, SpeechKit, Firebase, SIP) — `integrations` jadvalida shifrlangan; sozlamalar — `App\Support\Settings` (`Settings::DEFAULTS` ga yangi kalit qo'shiladi).
- **Filial chegarasi**: filialga tegishli modellar `App\Models\Concerns\BelongsToBranch` trait'ini ishlatadi — admin/operator/kassir faqat o'z filial(lar)ini ko'radi, superadmin hammasini.
- **Rollar**: `App\Enums\Role` (superadmin, admin, operator, cashier, driver) va ularning ruxsatlari shu enum'da; superadmin `Gate::before` orqali hamma narsaga ruxsatli.
- **Tillar**: o'zbek lotin (`uz`), o'zbek kirill (`oz`), rus (`ru`), ingliz (`en`). Frontend matnlari — inglizcha kalit + `lang/uz.json`, `lang/ru.json` (`lang/en.json` faqat kalit inglizcha matn bo'lmasa, masalan `offer.accepted`); React'da `useTrans()` → `t('Key')`. Kirill qo'lda yozilmaydi: uz o'zgargach `php artisan lang:cyrillic` (lang/oz yasaladi; test kalitlar mosligini tekshiradi). Android: values (uz), values-ru, values-en, values-b+uz+Cyrl (`android/tools/uz_cyrillic.py`).
- **Faqat Android**: haydovchi va mijoz ilovalari (qaror #71) — `android/` da `:core` (umumiy: tillar, saqlash, HTTP, Reverb, mavzu), `:driver`, `:client`; firma sozlamalari `android/buildSrc/Firm.kt` orqali. iOS yo'q. FastPanel ishlatilmaydi.
- Testlar majburiy (Pest, haqiqiy PostgreSQL+PostGIS bilan — demo serverda, pastga qarang). Dispatcher, narx, balans va buyurtma statuslari — albatta testlar bilan.

## Muhit

- **Lokal server yo'q** (Multipass VM 2026-10-26 da o'chirilgan). Kod, `composer`, `npm`, Pint, PHPStan, `tsc` — Mac'da (bazasiz). Bazaga muhtoj testlar — **demo serverda**: `deploy/test-remote.sh` ishchi papkani (commit qilinmaganlari bilan) `dtaxi-test@193.181.218.203:~/dtaxi-tests` ga rsync qilib, u yerda `php artisan test` (sudo'siz `dtaxi-test` foydalanuvchisi, kalit `~/.ssh/dtaxi_test_ed25519`; alohida `dtaxi_test` roli va bazasi — asosiy `dtaxi` bazasiga ulanish huquqi yo'q). Panel va ilovalarni sinash — demo `https://taxi.simulator.uz`.
- Server o'rnatish: `deploy/install.sh` (toza Ubuntu; `--prod --domain= --email=` firma serveri, `--demo`; `--dev` — lokal VM uchun edi). OSM ma'lumoti: `deploy/osm-prepare.sh` → `php artisan regions:import` (butun O'zbekiston, qaror #67).
- Telefoniya: Asterisk serverda; qo'ng'iroq mantig'i `App\Services\Telephony\CallCenter` (ARI), daemon `php artisan ari:listen` (supervisor `dtaxi-ari`; yangi kod uchun `ari:restart`). Mikrofon faqat https'da.
- Android: JDK 17 (`~/Library/Java/JavaVirtualMachines/jdk-17*`), Studio'ning JBR'i (25) Gradle 8.9 bilan ishlamaydi. Har `:driver:assembleDebug`/`:driver:assembleRelease` dan keyin haydovchi ilovasining universal APK'si `public/android/dtaxi.<versiya>.apk` ga tushadi (faqat oxirgisi, git'ga tushmaydi). Versiya — `1.0.<git commit'lar soni>`; mijoz ilovasi — o'z hisobi bilan: `1.0.<android/client va android/core ni o'zgartirgan commit'lar soni>` (APK `public/android/client/`). Haqiqiy telefon uchun: `./gradlew :driver:assembleRelease -Pfirm=lan` + Mac'da `python3 deploy/dev-lan-proxy.py`.

## Buyruqlar

```bash
deploy/test-remote.sh [--filter=...]                          # testlar demo serverda
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G # Mac'da (composer test — CI'da, bazali)
npm run check                    # frontend format va lint
npx tsc --noEmit                 # TypeScript
php artisan wayfinder:generate --with-form   # route'lar o'zgarsa
npm run build                    # frontend build (serverda dtaxi:update o'zi yig'adi)
php artisan telephony:sync       # ichki raqamlar va trunklarni Asterisk'ga qayta yozish
php artisan dtaxi:backup        # baza zaxirasi; dtaxi:update [--rollback] — firma serverida yangilash
```

## Kod uslubi

- Mavjud kodga moslashing: PHP atributlari (`#[Fillable]`), enum'lar, qisqa o'zbekcha izohlar faqat "nima uchun"ni tushuntirish uchun.
- PHPStan level 7 — xatolarni `@phpstan-ignore` bilan emas, to'g'ri tiplar bilan tuzating.
- Migratsiyalar faqat qo'shuvchi bo'lsin (yangilanishlar barcha firmalarga bepul yetkaziladi — reja §0.2).
