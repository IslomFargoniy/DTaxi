# Dtaxi — loyiha qoidalari

Telefon orqali ishlaydigan taksi dispetcherlik tizimi. Mahsulot nomi — **Dtaxi** (logo: D ichida lokatsiya belgisi); kod ichidagi `ibtaxi` nomlari (paket, buyruqlar, baza, VM) o'zgarmaydi. **Sotiladigan mahsulot**: har bir taksi firmasi o'z serverida alohida nusxa oladi. To'liq reja va qabul qilingan qarorlar — `docs/plan.md` (o'zgartirishdan oldin o'qing, qaror qabul qilinsa jadvalga qo'shing).

## Asosiy qoidalar

- **Firmaga xos hech narsa kodda yoki `.env` da emas.** Kalitlar (Click, Payme, SpeechKit, Firebase, SIP) — `integrations` jadvalida shifrlangan; sozlamalar — `App\Support\Settings` (`Settings::DEFAULTS` ga yangi kalit qo'shiladi).
- **Filial chegarasi**: filialga tegishli modellar `App\Models\Concerns\BelongsToBranch` trait'ini ishlatadi — admin/operator/kassir faqat o'z filial(lar)ini ko'radi, superadmin hammasini.
- **Rollar**: `App\Enums\Role` (superadmin, admin, operator, cashier, driver) va ularning ruxsatlari shu enum'da; superadmin `Gate::before` orqali hamma narsaga ruxsatli.
- **Tillar**: o'zbek lotin (`uz`), o'zbek kirill (`oz`), rus (`ru`), ingliz (`en`). Frontend matnlari — inglizcha kalit + `lang/uz.json`, `lang/ru.json` (`lang/en.json` faqat kalit inglizcha matn bo'lmasa, masalan `offer.accepted`); React'da `useTrans()` → `t('Key')`. Kirill qo'lda yozilmaydi: uz o'zgargach `php artisan lang:cyrillic` (lang/oz yasaladi; test kalitlar mosligini tekshiradi). Android: values (uz), values-ru, values-en, values-b+uz+Cyrl (`android/tools/uz_cyrillic.py`).
- **Faqat Android** haydovchi ilovasi; iOS yo'q. FastPanel ishlatilmaydi.
- Testlar majburiy (Pest, haqiqiy PostgreSQL+PostGIS bilan). Dispatcher, narx, balans va buyurtma statuslari — albatta testlar bilan.

## Muhit

- Lokal: Multipass virtual mashinasi `ibtaxi` (Ubuntu 24.04, IP `192.168.252.2`) — PostgreSQL+PostGIS, Redis, Nginx shu yerda. Loyiha papkasi VM ichida `/home/ubuntu/ibTaxi` ga ulangan.
- Kod, `composer`, `npm`, `php artisan` va testlar — Mac'da (`.env` VM'dagi bazaga ulanadi).
- Server o'rnatish: `deploy/install.sh` (toza Ubuntu; `--dev` lokal VM, `--prod --domain= --email=` firma serveri). OSM ma'lumoti: `deploy/osm-prepare.sh` → `php artisan regions:import --region=Fargona`.
- Telefoniya: Asterisk VM'da; qo'ng'iroq mantig'i `App\Services\Telephony\CallCenter` (ARI), daemon `php artisan ari:listen` (supervisor `ibtaxi-ari`; yangi kod uchun `ari:restart`). Panel brauzerda `https://192.168.252.2` (self-signed) — mikrofon faqat https'da.
- Android: JDK 17 (`~/Library/Java/JavaVirtualMachines/jdk-17*`), Studio'ning JBR'i (25) Gradle 8.9 bilan ishlamaydi. Har `assembleDebug`/`assembleRelease` dan keyin universal APK `public/android/dtaxi.<versiya>.apk` ga tushadi (faqat oxirgisi, git'ga tushmaydi). Versiya — `1.0.<git commit'lar soni>`. Haqiqiy telefon uchun: `./gradlew assembleRelease -Pfirm=lan` + Mac'da `python3 deploy/dev-lan-proxy.py`.

## Buyruqlar

```bash
php artisan test                 # testlar (ibtaxi_test bazasi)
composer test                    # Pint + PHPStan + testlar
npm run check                    # frontend format va lint
npx tsc --noEmit                 # TypeScript
php artisan wayfinder:generate --with-form   # route'lar o'zgarsa
npm run build                    # panel VM orqali http://192.168.252.2 da ochiladi
php artisan telephony:sync       # ichki raqamlar va trunklarni Asterisk'ga qayta yozish
php artisan ibtaxi:backup        # baza zaxirasi; ibtaxi:update [--rollback] — firma serverida yangilash
```

## Kod uslubi

- Mavjud kodga moslashing: PHP atributlari (`#[Fillable]`), enum'lar, qisqa o'zbekcha izohlar faqat "nima uchun"ni tushuntirish uchun.
- PHPStan level 7 — xatolarni `@phpstan-ignore` bilan emas, to'g'ri tiplar bilan tuzating.
- Migratsiyalar faqat qo'shuvchi bo'lsin (yangilanishlar barcha firmalarga bepul yetkaziladi — reja §0.2).
