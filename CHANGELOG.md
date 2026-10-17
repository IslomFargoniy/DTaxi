# O'zgarishlar tarixi

Versiya `VERSION` faylida. Firma serverida yangilash: `php artisan ibtaxi:update` (zaxira bilan),
muammo bo'lsa `php artisan ibtaxi:update --rollback`.

## 0.4.0 — 2026-10-21

- Filial ish vaqti va bayram kunlari (ishdan tashqari qo'ng'iroqqa xabar)
- Oldindan buyurtma; IVR avto-buyurtma (doimiy mijoz "1" ni bosadi)
- Kengaytirilgan hisobotlar va CSV eksport; operator KPI
- Haydovchi ilovasida qadam-baqadam navigatsiya (banner + ovoz)
- Soxta GPS aniqlash, audit jurnali, tizim monitoringi (Telegram ogohlantirish), zaxirani SFTP serverga yuborish
- Click/Payme orqali balansni to'ldirish
- Tuzatishlar: Horizon menyusi, APK yuklash limitlari

## 0.3.0 — 2026-10-17

- Telefoniya: Asterisk 20 (PJSIP Realtime, ARI), kiruvchi navbat va operatorlarga taqsimlash, IVR,
  liniyalar limiti, qora ro'yxat, suhbat yozuvlari (MP3), javobsiz qo'ng'iroqlar
- Operator softfoni brauzerda; kiruvchi qo'ng'iroq kartochkasi buyurtma formasini to'ldiradi
- Haydovchi ilovasidan dispetcherga qo'ng'iroq (WebRTC)
- Mijozga avtomatik ovozli xabarlar (tayinlandi, yetib keldi, kutmoqda, topilmadi, bekor qilindi)
- Ovozli bo'laklar (SpeechKit) — panelda tinglash, qayta generatsiya, o'z yozuvini yuklash
- Qo'ng'iroqlar monitoringi, tarixi va statistikasi
- Birinchi kirish ustasi, kunlik zaxira, `ibtaxi:update` / `--rollback`

## 0.2.0

- Android haydovchi ilovasi: liniya, takliflar, taksometr, xarita, chat, balans, avto-yangilanish

## 0.1.0

- Operator paneli, efir (taklif tarqatish), tariflar, filiallar, kassa, admin bo'limlari, OSM mo'ljallar
