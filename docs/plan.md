# ibTaxi — Loyiha rejasi

> Mahalliy taksi xizmati: mijoz **faqat telefon orqali** buyurtma beradi, operator buyurtmani tizimga kiritadi va tizim uni eng yaqin bo'sh haydovchiga yuboradi.
>
> **Mahsulot:** ibTaxi taksi firmalariga **sotiladi** — har bir firma o'z nusxasini (o'z serveri, o'z shartnomalari) oladi (qaror #24, §0).

Holat: **v5.0 — tasdiqlangan reja** · Sana: 2026-10-17

### Qabul qilingan qarorlar

| #   | Qaror                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Sana       |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 1   | Push: Firebase Cloud Messaging (bepul), MVP'dan boshlab                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | 2026-10-10 |
| 2   | Xarita: **bepul yondashuv** — MapLibre + OpenStreetMap (o'z serverimizda) + OSRM                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | 2026-10-10 |
| 3   | Haydovchi ilovasida xarita **ilova ichida**, darajasi: **(a)** xarita + marshrut chizig'i + masofa + ETA. Ovozli navigatsiya MVP'ga kirmaydi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | 2026-10-10 |
| 4   | **SMS ishlatilmaydi.** Mijozga barcha xabarlar Asterisk orqali avtomatik qo'ng'iroq + TTS (ovozli xabar) bilan yetkaziladi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 2026-10-10 |
| 5   | Haydovchi ilovasi: **Android 10+** (`minSdk 29`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | 2026-10-10 |
| 6   | ~~iOS ilovasi keyingi bosqichda~~ → **qaror #43 bilan bekor qilindi**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | 2026-10-10 |
| 7   | **Mijoz uchun ilova bo'lmaydi** (hech qachon). Buyurtma faqat telefon orqali: operator yoki IVR                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 2026-10-10 |
| 8   | **Jamoa: 1 dasturchi + AI (Claude Code).** Bosqichlar ketma-ket bajariladi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 2026-10-10 |
| 9   | **1 ta VPS** — Laravel, PostgreSQL, Redis, OSRM va Asterisk birga (FastPanel'siz — qaror #46)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | 2026-10-10 |
| 10  | **Bitta loyiha — faqat ibTaxi.** PBX boshqaruv paneli ibTaxi ichida quriladi, alohida loyiha yo'q. Asterisk noldan o'rnatiladi. panel.1call.uz — faqat **andoza** (qo'ng'iroq boshqaruvi funksiyalari va dialplan mantig'i uchun namuna)                                                                                                                                                                                                                                                                                                                                                                                                           | 2026-10-10 |
| 11  | **Qo'ng'iroqlar yozib olinadi:** kiruvchi (operator suhbatlari), operator callback'lari, haydovchi ↔ dispetcher qo'ng'iroqlari                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | 2026-10-10 |
| 12  | **SIP trunk: Sarkor Telecom** — asosiy sinovdan o'tgan provayder. Har firma o'z raqami va shartnomasini oladi (qaror #40, #45)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | 2026-10-10 |
| 13  | **Parallel liniyalar soni admin panelda sozlanadi** (panel.1call.uz kabi) — kodda qattiq yozilmaydi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | 2026-10-10 |
| 14  | **Audio bo'laklar oldindan generatsiya qilinadi — faqat ayol ovozida** (SpeechKit'da erkak o'zbek ovozi yo'q)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | 2026-10-10 |
| 15  | **Raqamlar ↔ filiallar universal bog'lanadi** (panelda sozlanadi): bitta raqam hamma filialga, har filialga alohida raqam, yoki bitta raqam tanlangan filiallarga                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | 2026-10-10 |
| 16  | **Mijozga avto-qo'ng'iroq ikki marta:** haydovchi tayinlanganda va haydovchi yetib kelganda                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | 2026-10-10 |
| 17  | **TTS xizmati: Yandex SpeechKit** (o'zbek tili `uz-UZ`) — bo'laklarni bir marta generatsiya qilish uchun                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 2026-10-10 |
| 18  | **Test serveri: Webdock.io (Daniya)** — toza Ubuntu, o'rnatish skripti bilan (qaror #46). Ishlab chiqish va sinov shu serverda                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | 2026-10-10 |
| 19  | **Xizmat nomi (IVR salomlashuvi uchun) admin panelda kiritiladi** — har filial uchun; saqlanganda salomlashuv audiosi avtomatik generatsiya qilinadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | 2026-10-10 |
| 20  | **Production serveri — har firmaning o'z serveri** (qaror #36). O'zbekistondagi hosting tavsiya etiladi (masalan UzCloud) — shaxsiy ma'lumotlar qonuni                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | 2026-10-10 |
| 21  | **Narx: boshlang'ich narx (ichida N km) + har keyingi km uchun narx.** Qiymatlar admin panelda sozlanadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 2026-10-10 |
| 22  | **To'lov faqat naqd** (mijoz haydovchiga). Mijoz uchun onlayn to'lov yo'q                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | 2026-10-10 |
| 23  | **Daromad: har buyurtmadan foiz (komissiya), haydovchi balansidan yechiladi.** Balans oldindan to'ldiriladi: Click/Payme orqali (haydovchi ID'si bo'yicha) yoki firma kassasiga naqd. Balans tugaguncha buyurtma oladi                                                                                                                                                                                                                                                                                                                                                                                                                             | 2026-10-10 |
| 24  | **ibTaxi — sotiladigan mahsulot.** Har bir firmaga alohida nusxa (o'z serveri). Barcha tashqi xizmatlar (Click, Payme, Sarkor/SIP, SpeechKit, Firebase) **firmaning o'z shartnoma/kalitlari** bilan — kodda hech narsa qattiq yozilmaydi, hammasi panelda sozlanadi                                                                                                                                                                                                                                                                                                                                                                                | 2026-10-10 |
| 25  | **Kassir roli qo'shiladi** — firma kassasida haydovchi balansini naqd to'ldiradi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | 2026-10-10 |
| 26  | **Haydovchilar o'z mashinasi bilan ishlaydi** (hamkor, firma xodimi emas). Mashina haydovchiga tegishli; admin ro'yxatdan o'tkazadi va tasdiqlaydi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | 2026-10-10 |
| 27  | **Tarif klasslari: Standart / Komfort / Yuk / Dostavka** — o'rnatishda tayyor yaratiladi; admin panelda tahrirlash, o'chirish, yangi qo'shish mumkin. Har klassning o'z narxi; mashina bir nechta klassda ishlay oladi                                                                                                                                                                                                                                                                                                                                                                                                                             | 2026-10-10 |
| 28  | **Buyurtma "efir" usulida tarqatiladi:** radius ichidagi barcha mos bo'sh haydovchilarga bir vaqtda, **kim birinchi qabul qilsa** — o'sha oladi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 2026-10-11 |
| 29  | **Rad etish uchun jarima va reyting pasayishi** — qabul qilib keyin bekor qilish va operator tayinlagan buyurtmani rad etish uchun. Qiymatlar panelda sozlanadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 2026-10-11 |
| 30  | **Birinchi mijozlar uchun mo'ljal hudud: Farg'ona vodiysi** (Farg'ona, Andijon, Namangan) — xarita, mo'ljallar va sinovlar shu hudud bo'yicha tayyorlanadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 2026-10-11 |
| 31  | **Filial hududi admin panelda tanlanadi:** viloyat → shahar yoki tuman. Har firma o'z filiallarini o'zi belgilaydi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | 2026-10-11 |
| 32  | **Hajm: kuniga 1000+ buyurtma** — tizim shu yuklamaga mo'ljallanadi (§9.3)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | 2026-10-11 |
| 33  | **Kutish puli, tungi narx, shahar tashqarisi narxi** — har biri admin panelda **yoqiladi/o'chiriladi** va sozlanadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | 2026-10-11 |
| 34  | **Boshlang'ich sozlamalar** (panelda o'zgartiriladi): komissiya **10%**, minimal balans **5 000 so'm**, yaxlitlash **500 so'm**, qo'ng'iroq yozuvlari **90 kun**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | 2026-10-11 |
| 35  | **Haydovchi ilovasi — har firmaga alohida** (o'z nomi, logotipi, server manzili, Firebase'i) — bitta kod bazasidan build qilinadi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | 2026-10-11 |
| 36  | **Sotish modeli: bir martalik sotuv.** **Har firma o'z serverini oladi**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 2026-10-11 |
| 37  | **Haydovchi ilovasi Google Play'ga chiqarilmaydi** — APK firma serveridan tarqatiladi, ilova ichida avto-yangilanish                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | 2026-10-11 |
| 38  | **Yangilanishlar bepul** — sotuvdan keyin barcha yangi versiyalar xaridorlarga bepul                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | 2026-10-11 |
| 39  | **Kod himoyasi hozircha yo'q** (litsenziya kaliti, domen bog'lash va h.k. — kerak bo'lsa keyin)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 2026-10-11 |
| 40  | **SIP trunk shartnomasini har firma o'zi tuzadi.** Test uchun alohida akkauntni loyiha muallifi o'zi oladi. Daniya test serveriga ruxsat bo'lmasa — test uchun O'zbekistonda server olinadi                                                                                                                                                                                                                                                                                                                                                                                                                                                        | 2026-10-11 |
| 41  | **Qisqa raqam** (masalan 1XXX) rejada bor — panelda oddiy raqam (DID) sifatida qo'shiladi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | 2026-10-11 |
| 42  | **Masked call (yashirin qo'ng'iroq) yo'q.** Haydovchi mijozga o'z telefonidan to'g'ridan-to'g'ri qo'ng'iroq qiladi (mijoz raqami faqat faol buyurtma davomida ko'rinadi)                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | 2026-10-17 |
| 43  | **iOS ilovasi yo'q** — faqat Android (App Store har firma uchun yillik 99$ va alohida akkaunt talab qiladi)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | 2026-10-17 |
| 44  | **Tillar: o'zbek (lotin) + rus.** Panel va haydovchi ilovasi ikki tilda; standart tilni firma tanlaydi, har foydalanuvchi o'zi almashtira oladi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 2026-10-17 |
| 45  | **O'z taksi firmasi / 1call'dan ko'chirish yo'q.** Loyiha to'liq mahsulot sifatida sotiladi; har firma o'z raqamini oladi. Pilot — **birinchi xaridor firma** bilan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | 2026-10-17 |
| 46  | **FastPanel'dan to'liq voz kechildi.** Test va xaridor serverlari — toza Ubuntu LTS + o'rnatish skripti (Nginx, PHP-FPM, PostgreSQL, Redis, Asterisk, OSRM, Supervisor, Certbot)                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | 2026-10-17 |
| 47  | **Haydovchi ↔ dispetcher aloqasi: ilova ichida qo'ng'iroq (WebRTC, yoziladi) + chat**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | 2026-10-17 |
| 48  | **Haydovchi joylashuvi PostGIS'da** (`drivers.last_location` + GIST indeks), Redis GEO o'rniga: ~18 yangilanish/soniya PostgreSQL uchun yengil, holat va joylashuv bitta tranzaksiyada — "kim birinchi" ishonchliroq                                                                                                                                                                                                                                                                                                                                                                                                                               | 2026-10-18 |
| 49  | **Ilova ichida ovozli navigatsiya: OSRM manevrlari + telefonning TTS dvigateli** (Ferrostar + Valhalla o'rniga). Allaqachon ishlab turgan OSRM'dan foydalaniladi, qo'shimcha server yo'q; ko'rsatma banneri doim, ovoz — telefonda ilova tili (o'zbek/rus) bo'lsa                                                                                                                                                                                                                                                                                                                                                                                  | 2026-10-21 |
| 50  | **Efir raundlari**: har qayta efir (haydovchi bekor qildi, qayta efir, ustama) yangi raund — hamma yana ko'radi, faqat bekor qilgan haydovchidan tashqari. Eng katta radiusda ham olinmasa buyurtma belgilanadi va `orders:rebroadcast` har daqiqada qayta yuboradi                                                                                                                                                                                                                                                                                                                                                                                | 2026-10-22 |
| 51  | **Holat o'zgarishlari shartli UPDATE / qulf bilan** (arrive/start/complete/cancel); takroriy `complete` xavfsiz (ilova internet uzilganda qayta yuboradi). Masofa kam ko'rsatilsa komissiya GPS treki bo'yicha                                                                                                                                                                                                                                                                                                                                                                                                                                     | 2026-10-22 |
| 52  | **Tig'iz soat koeffitsienti va chegirmalar buyurtma snapshot'iga yoziladi** (yaratilgan paytdagi shart); korporativ mijoz naqd to'lamaydi — narx haydovchi balansiga, kompaniyaga oylik hisob-faktura                                                                                                                                                                                                                                                                                                                                                                                                                                              | 2026-10-22 |
| 53  | **Navbat joylari**: radius ichidagi bo'sh haydovchilar kelish tartibida; shu hududdan chiqqan buyurtma birinchi bosqichda faqat navbat boshidagiga. Past reytingli haydovchi eng yaqin radiusda ko'rmaydi                                                                                                                                                                                                                                                                                                                                                                                                                                          | 2026-10-22 |
| 54  | **Ko'cha va uy raqami** OSM'dan `landmarks` ga (category `address`/`street`), alohida jadval emas — bitta qidiruv; qidiruv so'zlar bo'yicha                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | 2026-10-22 |
| 55  | **Mijoz bahosi**: operator kiritadi yoki safardan keyingi IVR qo'ng'iroqda 1–5 (standart o'chiq — qo'ng'iroq pulli); shikoyat/unutilgan buyum jurnali buyurtma va yozuvga bog'lanadi                                                                                                                                                                                                                                                                                                                                                                                                                                                               | 2026-10-22 |
| 56  | **Mahsulot nomi — Dtaxi**, logo: qora "D" ichida lokatsiya belgisi (teshik), sariq #FFC800 fonda. Foydalanuvchiga ko'rinadigan nomlar almashtirildi; ichki `ibtaxi` nomlari (Android paketi, artisan buyruqlari, baza, VM) — o'rnatilgan ilovalar va serverlar buzilmasligi uchun o'zgarmaydi                                                                                                                                                                                                                                                                                                                                                      | 2026-10-21 |
| 57  | **Ovozli navigatsiya** — telefonning TTS dvigateli (Samsung/Google), internetsiz; o'zbekcha ovoz yo'q, shuning uchun ko'rsatmalar ruscha (bo'lmasa inglizcha) aytiladi, banner ilova tilida. Haydovchi sozlamalarda ovozni tanlaydi. SpeechKit bilan o'zbekcha iboralar kerak emas                                                                                                                                                                                                                                                                                                                                                                 | 2026-10-21 |
| 58  | **Birinchi ishga tushirish — `ibtaxi:init`** (ochiq sozlash ustasi o'rniga): `install.sh` bitta filial va superadmin (`admin@<domen>`, tasodifiy parol) yaratadi, login/parol o'rnatuvchiga bir marta ko'rsatiladi; begona odam superadmin bo'lib ololmaydi. Parol — `ibtaxi:admin-password`                                                                                                                                                                                                                                                                                                                                                       | 2026-10-21 |
| 59  | **Operator → haydovchi qo'ng'irog'i push orqali**: ilova SIP'da doim ro'yxatda turmaydi — server Reverb + FCM bilan "kiruvchi qo'ng'iroq" yuboradi, ilova telefon kabi to'liq ekranda jiringlaydi; javob bersa ilova o'zi `answer-{callId}` ga qo'ng'iroq qiladi va operator bilan ulanadi. Rad etish — operatorga "band", `calls.driver_ring_seconds` (30 s) da javob bo'lmasa — "javob yo'q"                                                                                                                                                                                                                                                     | 2026-10-21 |
| 60  | **Mo'ljallar uchun ochiq joylar ma'lumoti** (OSM'dan tashqari): Overture Maps places (CDLA-Permissive-2.0; ichidagi Foursquare yozuvlari Apache-2.0 + NOTICE, AllThePlaces CC0) va Wikidata (CC0, faqat foydali turlar). `deploy/places-prepare.sh` BBOX bo'yicha DuckDB bilan oladi, `landmarks:import --source=` import qiladi; `landmarks.source`/`external_id`, OSM'da 150 m ichida o'xshash nom bo'lsa — takror. Foursquare OS Places to'g'ridan-to'g'ri olinmaydi (Hugging Face'da login talab qiladi). Yandex/Google/2GIS va sayt skreypingi — hech qachon. Xaritada atributsiya: "© OpenStreetMap · Overture Maps Foundation · Foursquare" | 2026-10-21 |

---

## 0. Mahsulot modeli — har firmaga alohida nusxa (qaror #24)

ibTaxi bitta firma uchun emas, **ko'plab taksi firmalariga sotiladigan mahsulot**. Har bir xaridor firma:

- o'z serverida (o'z VPS'i) **alohida nusxa** oladi — ma'lumotlar firmalar o'rtasida aralashmaydi
- barcha tashqi xizmatlar bilan **o'zi shartnoma tuzadi** va kalitlarini panelga kiritadi

**Asosiy qoida: firmaga xos hech narsa kodda yoki `.env` da emas — superadmin panelida "Integratsiyalar" bo'limida.**

| Integratsiya                       | Firma o'zi oladi                                                | Panelda kiritiladi                                         |
| ---------------------------------- | --------------------------------------------------------------- | ---------------------------------------------------------- |
| **Click**                          | Merchant shartnoma                                              | `merchant_id`, `service_id`, `secret_key`                  |
| **Payme**                          | Merchant shartnoma                                              | `merchant_id`, kalit (test/prod)                           |
| **SIP trunk** (Sarkor yoki boshqa) | Telefon raqam + kanallar                                        | host, login, parol, raqam(lar), kanallar soni (§5.2, §5.3) |
| **Yandex SpeechKit**               | Yandex Cloud akkaunt                                            | API kalit, ovoz (zamira/yulduz/nigora)                     |
| **Firebase (FCM)**                 | O'z Firebase loyihasi (bepul) — firma ilovasi uchun (qaror #35) | service account JSON                                       |
| **Xarita**                         | — (bepul, OSM)                                                  | Hudud (viloyat) — o'rnatishda tanlanadi                    |
| **Brending**                       | —                                                               | Firma nomi, logotip, ranglar, xizmat nomi (IVR)            |
| **Til**                            | —                                                               | Standart til: o'zbek / rus (qaror #44)                     |

- Maxfiy kalitlar bazada **shifrlangan** holda saqlanadi (Laravel `encrypted` cast); panelda faqat oxirgi 4 belgisi ko'rinadi
- Har integratsiya yonida **"Ulanishni tekshirish"** tugmasi (masalan, SpeechKit'dan test audio, Click/Payme test rejimi, SIP trunk holati)
- Integratsiya sozlanmagan bo'lsa tizim baribir ishlaydi: masalan Click/Payme yo'q → faqat kassa orqali to'ldirish; SpeechKit yo'q → avto-qo'ng'iroqlar o'chiq

**O'rnatish va yangilash:**

- **O'rnatish skripti** (bash/Ansible, qaror #46): toza Ubuntu LTS VPS → to'liq tizim (Nginx, PHP-FPM, Laravel, PostgreSQL+PostGIS, Redis, Asterisk, coturn, OSRM, tiles, Supervisor, Certbot SSL, firewall, fail2ban, zaxira cron) bitta buyruqda. Yangi xaridorni ulash vaqti — soatlar, kunlar emas
- **Birinchi ishga tushirish (qaror #58):** `install.sh` filial va superadmin yaratadi, login/parol o'rnatuvchiga beriladi; firma nomi, til, hudud, integratsiyalar — keyin panelda
- **Yangilanishlar:** barcha nusxalar bitta asosiy kod bazasidan (git tag/release) yangilanadi — firmaga xos o'zgarishlar kodda emas, sozlamalarda bo'lgani uchun yangilash xavfsiz. `php artisan ibtaxi:update` (migratsiya + kesh + Asterisk reload)
- **Sotish: bir martalik** (qaror #36) — har firma **o'z serveri**ni oladi (O'zbekistondagi hosting tavsiya etiladi, masalan UzCloud), siz o'rnatib berasiz. Pilot — birinchi xaridor firma bilan (qaror #45). **Yangilanishlar bepul** (qaror #38). Kod himoyasi hozircha yo'q (qaror #39)

### 0.1 Haydovchi ilovasi — har firmaga alohida (qaror #35)

Har firma o'z serveriga ega bo'lgani uchun haydovchi ilovasi ham **har firmaga alohida** build qilinadi — lekin **kod bitta**:

- Android loyihada firmaga xos hamma narsa **bitta konfiguratsiya faylida**: `android/firms/<firma>/firm.properties` — `applicationId` (masalan `uz.ibrattaxi.driver`), ilova nomi, server URL, ranglar + `logo.png` + `google-services.json` (firmaning o'z Firebase'i)
- Build: `./gradlew assembleRelease -Pfirm=ibrattaxi` → tayyor APK/AAB. Yangi firma qo'shish = yangi papka, kod o'zgarmaydi
- Kod yangilanganda barcha firmalar ilovalari shu bitta buyruq bilan qayta build qilinadi
- Server URL ilova ichiga yoziladi — haydovchi hech narsa kiritmaydi, faqat login/parol
- **Tarqatish — Google Play'siz (qaror #37):**
    - APK firma serverida turadi: `https://<firma-domeni>/driver.apk` — admin panelda QR kod va havola (yangi haydovchiga ko'rsatiladi)
    - Birinchi o'rnatishda Android "noma'lum manbalardan o'rnatish" ruxsatini so'raydi — haydovchi uchun rasmli yo'riqnoma
    - **Ilova ichida avto-yangilanish:** ilova har ochilganda `/api/driver/v1/app-version` ni tekshiradi → yangi versiya bo'lsa yuklab oladi va o'rnatishni taklif qiladi (Android `PackageInstaller`, `REQUEST_INSTALL_PACKAGES` ruxsati). **Majburiy yangilanish** belgisi: eski versiya API mos kelmasa — yangilamaguncha liniyaga chiqa olmaydi
    - APK bitta imzo kaliti (keystore) bilan imzolanadi — **har firma uchun alohida keystore**, xavfsiz saqlanadi (yo'qolsa yangilanish o'rnatilmaydi, ilovani o'chirib qayta o'rnatish kerak bo'ladi)
    - FCM Google Play'siz ham ishlaydi — faqat telefonda **Google Play Services** bo'lishi kerak (deyarli barcha Android telefonlarda bor)

### 0.2 Yangilanishlarni yetkazish (qaror #38)

Barcha firmalarga yangi versiyalar bepul — shuning uchun yangilash **oson va xavfsiz** bo'lishi kerak:

- **Versiyalash:** asosiy repoda `v1.0.0`, `v1.1.0`… teglar + `CHANGELOG.md` (o'zbekcha — nima o'zgardi)
- **Server yangilash:** har firma serverida `php artisan ibtaxi:update` → kerakli teg'ni oladi (git deploy key bilan, faqat o'qish), `composer install`, `npm run build`, migratsiyalar, kesh, Asterisk reload, navbat worker'larini qayta ishga tushirish. Xato bo'lsa — oldingi versiyaga qaytish (`--rollback`)
- **Yangilashdan oldin** avtomatik DB zaxira nusxasi
- **Haydovchi ilovasi:** yangi APK firma serverga yuklanadi → ilovalar avto-yangilanishni o'zi ko'radi (qaror #37)
- **Migratsiyalar faqat "qo'shuvchi"** (ustun/jadval qo'shish) — eski versiyadagi ma'lumot buzilmaydi; ilova va API orqaga mos (eski ilova yangi server bilan ishlay oladi, majburiy yangilanishgacha)
- Ko'p firma bo'lganda: barcha serverlarni bitta buyruq bilan yangilash skripti (Ansible inventory — firmalar ro'yxati)

---

## 1. Texnologiyalar (stack)

| Qatlam               | Texnologiya                                                    | Izoh                                                                                                                                                                         |
| -------------------- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend              | Laravel 13 (PHP 8.3+)                                          | Starter kit allaqachon o'rnatilgan (Inertia 3, Fortify, Wayfinder)                                                                                                           |
| Admin/Operator panel | React 19 + Inertia 3 + Tailwind 4 + shadcn/ui                  | Bitta SPA ichida rollarga qarab sahifalar                                                                                                                                    |
| Ruxsatlar            | spatie/laravel-permission                                      | Rollar + permissionlar                                                                                                                                                       |
| Ma'lumotlar bazasi   | PostgreSQL 16+ + **PostGIS**                                   | Geo-so'rovlar (eng yaqin haydovchi) uchun PostGIS shart                                                                                                                      |
| Real-time            | **Laravel Reverb** (WebSocket)                                 | Operator paneli va haydovchi ilovasi uchun jonli yangilanish                                                                                                                 |
| Navbat (queue)       | Redis + Laravel Horizon                                        | Buyurtma taklifi taymerlari, avtomatik qo'ng'iroqlar, push                                                                                                                   |
| Kesh / navbat        | Redis                                                          | Har 3–5 soniyada keladigan koordinatalar uchun tez qatlam                                                                                                                    |
| Android              | Kotlin + Jetpack Compose, **Android 10+** (`minSdk 29`)        | Foreground service (`foregroundServiceType=location`) — fonda GPS                                                                                                            |
| Push                 | Firebase Cloud Messaging — **bepul**                           | MVP'dan boshlab. Reverb WebSocket asosiy kanal, FCM har bir buyurtma taklifida parallel yuboriladi (ilova fonda/yopiq bo'lsa uyg'otadi). Firebase'dan faqat push ishlatiladi |
| PBX                  | Asterisk 20/22 LTS (PJSIP + WebRTC)                            | Operator brauzerdan qo'ng'iroq qabul qiladi (JsSIP / SIP.js)                                                                                                                 |
| Xarita               | MapLibre GL JS (web), MapLibre Native (Android)                | OSM ma'lumotlari, tiles o'z serverimizda (`.pmtiles`) — bepul                                                                                                                |
| Marshrut / ETA       | OSRM (Docker, o'z serverimizda)                                | Dispatcher uchun `table`, ilova uchun `route` API — bepul                                                                                                                    |
| Server               | 1 ta VPS, toza Ubuntu LTS (FastPanel'siz)                      | O'rnatish skripti bilan; Asterisk ham shu serverda (§9)                                                                                                                      |
| Tillar               | Laravel lokalizatsiya + `react-i18next`; Android `strings.xml` | O'zbek (lotin) + rus (qaror #44)                                                                                                                                             |

---

## 2. Rollar va huquqlar

| Rol            | Ko'lam            | Asosiy imkoniyatlar                                                                                                                                                                                                                                                                                                                                     |
| -------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **superadmin** | Butun tizim       | Filiallar yaratish, adminlar tayinlash, global tariflar, umumiy hisobotlar, tizim sozlamalari                                                                                                                                                                                                                                                           |
| **admin**      | Faqat o'z filiali | Operator/haydovchilarni boshqarish, tariflar (filial uchun), haydovchi balansi, hisobotlar, qora ro'yxat                                                                                                                                                                                                                                                |
| **operator**   | O'z filiali       | Qo'ng'iroq qabul qilish, buyurtma yaratish/bekor qilish, haydovchini qo'lda tayinlash, xaritani kuzatish                                                                                                                                                                                                                                                |
| **kassir**     | O'z filiali       | Haydovchini ID/pozivnoy/telefon bo'yicha topish, balansini **naqd to'ldirish** (chek chop etish ixtiyoriy), o'z kirimlari tarixi, **kassa smenasi**: smena ochish/yopish, smena yakunida jami naqd summa (admin bilan solishtirish uchun). Buyurtmalar, tariflar, sozlamalarni ko'rmaydi; balansdan pul yechish yoki tuzatish qila olmaydi (bu — admin) |
| **driver**     | O'z filiali       | Faqat mobil ilova: liniyaga chiqish, buyurtma qabul qilish, statuslarni o'zgartirish, dispetcher bilan qo'ng'iroq/chat                                                                                                                                                                                                                                  |

**Multi-filial (multi-tenant):** barcha asosiy jadvallarda `branch_id`. Laravel global scope orqali admin/operator faqat o'ziga biriktirilgan filial(lar) ma'lumotini ko'radi (operator bir nechta filialga biriktirilishi mumkin — §5.2a). Superadmin uchun scope o'chiriladi.

---

## 3. Asosiy jarayon (buyurtma oqimi)

```
Mijoz qo'ng'iroq qiladi
   │
   ▼
Asterisk: IVR (ixtiyoriy salomlashuv) → Queue → bo'sh operator
   │                                         (WebRTC, brauzerda)
   ▼
Operator panelida avtomatik "pop-up":
   • Mijoz raqami (Caller ID)
   • Avvalgi buyurtmalari, saqlangan manzillari ("Uy", "Ish")
   • Qora ro'yxatdami?
   │
   ▼
Operator: mo'ljal / manzilni kiritadi (qidiruv + xaritada nuqta)
          ixtiyoriy: borish manzili, tarif klassi (Standart/Komfort/Yuk/Dostavka), izoh
   │
   ▼
"Buyurtma berish" → status: SEARCHING
   │
   ▼
Dispatcher (backend job):
   1. PostGIS: radius ichidagi mos bo'sh haydovchilar (boshlanishi 3 km)
   2. EFIR: radius ichidagi barcha mos haydovchilarga bir vaqtda taklif
        ├─ Birinchi "Qabul qildim" bosgan → ASSIGNED, qolganlarda taklif darhol yo'qoladi
        ├─ 20 soniyada hech kim olmadi → radius kengayadi, yangi haydovchilarga ham efir
        └─ Baribir hech kim yo'q → operatorga ogohlantirish + qo'lda tayinlash
   │
   ▼
ASSIGNED → mijozga avtomatik qo'ng'iroq (TTS): "Sizga oq Cobalt, 01A123BC, 5 daqiqada yetib keladi"
           (mijoz hali operator bilan gaplashayotgan bo'lsa — suhbat tugashini kutib, keyin qo'ng'iroq qilinadi)
   │
   ▼
Haydovchi yetib keldi → ARRIVED
   → mijozga avtomatik qo'ng'iroq (TTS: "Sizni haydovchi kutmoqda. Oq Cobalt, 01A123BC")
   │
   ▼
Mijoz chiqdi → IN_PROGRESS (taksometr ishlaydi)
   │
   ▼
Manzilga yetdi → COMPLETED (narx hisoblanadi, naqd to'lov, komissiya haydovchi balansidan yechiladi)
```

### Buyurtma statuslari

`NEW → SEARCHING → ASSIGNED → ARRIVED → IN_PROGRESS → COMPLETED`
Har qanday bosqichda: `CANCELLED_BY_CLIENT`, `CANCELLED_BY_OPERATOR`, `CANCELLED_BY_DRIVER`, `NO_DRIVER_FOUND`.

Barcha status o'zgarishlari `order_events` jadvaliga yoziladi (kim, qachon, qayerda) — nizolarni hal qilish uchun.

---

## 4. Xarita — ✅ qaror: bepul yondashuv (OSM + MapLibre + OSRM)

### Tanlangan yechim

1. **Xaritani ko'rsatish (tiles)** — OSM asosida:
    - Web panel: **MapLibre GL JS**
    - Android: **MapLibre Native**
    - Tile manbai: o'z serverimizda — Protomaps/OpenMapTiles asosida bitta `.pmtiles` fayl, Nginx orqali beriladi. Oyiga bir marta OSM'dan yangilanadi (cron)
    - **Hudud o'rnatishda tanlanadi** (§0): butun O'zbekiston (~300–500 MB) yoki faqat kerakli viloyatlar. Birinchi mijozlar uchun — **Farg'ona vodiysi** (Farg'ona, Andijon, Namangan viloyatlari, qaror #30): fayl kichikroq, OSRM uchun RAM kamroq
2. **Yo'l masofasi va ETA** — **OSRM** o'z serverimizda (Docker, tanlangan hudud OSM ma'lumoti bilan; butun O'zbekiston ~1–2 GB RAM, vodiy — kamroq). "Eng yaqin haydovchi"ni to'g'ri chiziq emas, yo'l bo'yicha topish uchun kerak. Bepul, cheksiz.
3. **Manzil qidirish (geocoding) — eng muhim nuqta:**
    - Mijozlar odatda **mo'ljal** aytadi ("Chorsu bozori oldi", "3-maktab", "Korzinka Yunusobod"), uy raqami emas.
    - Shuning uchun **o'zimizning mo'ljallar bazasi** (`landmarks` jadvali) eng to'g'ri yechim: nomi, sinonimlari (lotin/kirill/ruscha), koordinatasi. PostgreSQL `pg_trgm` bilan noaniq qidiruv.
    - Operator har yangi mo'ljalni xaritada bir marta belgilaydi → bazaga qo'shiladi → keyingi safar avtomatik chiqadi. 2–3 oyda shahar to'liq qoplanadi.
    - Qo'shimcha: OSM'dan POI'larni (maktab, bozor, masjid, do'kon) boshlang'ich import qilish.
    - ⚠️ Farg'ona, Andijon, Namangan'da OSM ma'lumoti Toshkentdagidan kamroq (uy raqamlari, mahalla nomlari, mo'ljallar). Shuning uchun har yangi firmaga o'rnatishda (pilotdan **oldin**) uning shahar(lar)i uchun asosiy mo'ljallarni (bozorlar, maktablar, kasalxonalar, to'yxonalar, avtovokzal, mahallalar) bazaga kiritib qo'yish tavsiya etiladi — mahalliy operatorlar bilan 1–2 kunlik ish
4. **Haydovchi ilovasida xarita — ilova ichida, (a) daraja:**
    - MapLibre Native xaritasi: haydovchining o'z joylashuvi, mijoz nuqtasi (A), borish manzili (B)
    - **Marshrut chizig'i** — OSRM `route` API orqali (backend proksi qiladi: `/api/driver/v1/route`)
    - **Masofa va ETA** — ekranda doim ko'rinib turadi, haydovchi harakatlanganda har 15–30 soniyada yoki marshrutdan chiqqanda qayta hisoblanadi
    - Ovozli "burilish-burilish" navigatsiya: OSRM manevrlari + telefonning TTS dvigateli (qaror #49) — banner, 300 m va 60 m oldin ovozli ko'rsatma, yo'ldan chiqsa marshrut qayta hisoblanadi
    - Zaxira: "Navigatorda ochish" tugmasi (telefondagi tashqi navigator ilovasiga deep-link) — haydovchi xohlasa
5. **Operator paneli xaritasi:** haydovchilar (bo'sh/band/oflayn ranglarda, real-time Reverb orqali), faol buyurtmalar, mo'ljal belgilash uchun xaritaga bosish

**Natija:** xarita uchun oylik/yillik to'lovsiz ishlaydigan tizim; aniqlik o'z mo'ljallar bazamiz hisobiga vaqt o'tishi bilan oshib boradi (aynan mahalliy mijozlar aytadigan nomlar bo'ladi).

---

## 5. Asterisk / telefoniya integratsiyasi

### Arxitektura

```
Mijoz telefoni ──► SIP trunk (provayder) ──► Asterisk ──► Queue "operators"
                                                │
                              PJSIP over WSS (WebRTC)
                                                │
                                   Operator brauzeri (JsSIP)
                                                │
Asterisk AMI/ARI ──► Laravel listener (daemon) ──► Reverb ──► Operator paneli (pop-up)
```

### Asosiy funksiyalar

- **Queue** (strategiya: `rrmemory` yoki `leastrecent`), navbatda kutish musiqasi, "operatorlar band" xabari
- **Operator softphone** React panel ichida: qabul qilish, ushlab turish (hold), o'tkazish (transfer), ovozni o'chirish, "Pauza/Tanaffus" holati
- **Caller ID pop-up**: AMI `AgentConnect` hodisasi → Laravel → Reverb → shu operatorning brauzerida mijoz kartochkasi ochiladi
- **Qo'ng'iroqlarni yozib olish — majburiy (qaror #11)**:
    - Asterisk `MixMonitor` — operator suhbatlari, operator callback'lari, haydovchi ↔ dispetcher qo'ng'iroqlari; avtomatik TTS qo'ng'iroqlari yozilmaydi (faqat natijasi saqlanadi). Haydovchi mijozga o'z telefonidan qo'ng'iroq qiladi — bu tizimdan o'tmaydi va yozilmaydi (qaror #42)
    - Qo'ng'iroq tugagach queue job: WAV → **MP3/Opus mono** ga o'giriladi (~0.1–0.2 MB/daqiqa), `calls.recording_path` ga yoziladi, buyurtmaga bog'lanadi
    - Saqlash: `storage/app/recordings/{branch}/{Y}/{m}/{d}/` — fayllar faqat avtorizatsiyadan o'tgan admin uchun Laravel orqali beriladi (to'g'ridan-to'g'ri URL yo'q)
    - Disk hisobi (taxmin): kuniga 1000 qo'ng'iroq × 1.5 daq × 0.15 MB ≈ **~225 MB/kun, ~7 GB/oy**
    - Muddati o'tgan yozuvlar avtomatik o'chiriladi (`schedule` buyrug'i, muddat panelda — standart **90 kun**, qaror #34); o'chirishdan oldin ixtiyoriy tashqi arxivga ko'chirish
    - Operator va admin uchun tinglash pleyeri (buyurtma kartochkasida va qo'ng'iroqlar tarixida)
- **CDR** → PostgreSQL (qo'ng'iroqlar hisoboti: javobsiz, kutish vaqti, operator samaradorligi)
- **Avtomatik qo'ng'iroq (outbound) + TTS** — mijozga barcha xabarlar shu orqali (SMS yo'q), batafsil §5.4
- **Haydovchi → mijoz:** ilovada "Mijozga qo'ng'iroq" tugmasi telefonning oddiy qo'ng'iroq oynasini ochadi (qaror #42). Mijoz raqami faqat buyurtma faol bo'lgan paytda ko'rinadi, yakunlangach yashiriladi
- **Haydovchi ↔ dispetcher (qaror #47):**
    - **Qo'ng'iroq:** ilovada "Dispetcherga qo'ng'iroq" tugmasi → WebRTC (SIP over WSS, ilova ichida, internet orqali — bepul) → Asterisk'dagi filial navbatiga, operator panelida "Haydovchi #pozivnoy, buyurtma #123" bilan ochiladi; suhbat yoziladi
    - **Chat:** ilovada buyurtmaga bog'langan chat + umumiy chat; operator panelida haydovchilar chatlari ro'yxati, o'qilmaganlar soni; Reverb orqali real vaqtda, ilova yopiq bo'lsa FCM; tayyor tezkor javoblar ("Mijoz chiqmayapti", "Manzil topilmadi", "Yo'ldaman"); rasm yuborish (masalan joy rasmi)
- **Javobsiz qo'ng'iroqlar ro'yxati** → operator qayta qo'ng'iroq qiladi (callback)

### 5.1 Integratsiya usuli

- **AMI** (hodisalar uchun) — `marcelog/pami` yoki o'zimiz yozgan yengil TCP client, Supervisor ostida `php artisan pbx:listen` daemon
- **ARI** (outbound va IVR boshqaruvi uchun) — REST + WebSocket
- WebRTC uchun: Asterisk'da `res_pjsip` + `transport-wss`, haqiqiy SSL sertifikat (Let's Encrypt), STUN/TURN (coturn) — NAT ortidagi operatorlar uchun
- **Asterisk Realtime (PostgreSQL)** — PJSIP endpointlar, auth, AOR, navbatlar va navbat a'zolari to'g'ridan-to'g'ri ibTaxi bazasidagi `ps_*`, `queues`, `queue_members` jadvallaridan o'qiladi. Panelda operator qo'shilsa — Asterisk'ni qayta ishga tushirmasdan darhol ishlaydi. Dialplan (`extensions.conf`) asosan statik, faylda va git'da saqlanadi

### 5.2 SIP trunk — Sarkor Telecom (qaror #12, #40, #45)

Har bir xaridor firma o'z raqami va SIP trunkini **o'zi** oladi (Sarkor yoki boshqa SIP provayder) va ma'lumotlarini panelga kiritadi. Biz faqat ulash va sinovni bajaramiz.

**Ulash tartibi (har yangi firma uchun):**

1. **Ma'lumot olish** — firma provayderdan: SIP host/port, login/parol (yoki IP-autentifikatsiya), raqam(lar), DID formati (`998XXXXXXXXX` / `+998…`), kodeklar (odatda G.711 alaw), kanallar soni
2. **Provayderga server IP'sini berish** (IP whitelist kerak bo'lsa)
3. **Panelda kiritish** — "SIP trunklar" va "Telefon raqamlari" modullari (§5.5); Asterisk Realtime orqali darhol ishlaydi
4. **Sinov** — kiruvchi qo'ng'iroq → navbat → operator; chiquvchi TTS qo'ng'iroq; Caller ID to'g'ri ko'rinishi; qisqa raqam (agar bo'lsa)

**Ishlab chiqish va sinov uchun:** loyiha muallifining alohida Sarkor test akkaunti (qaror #40). Sarkor Daniya test serveriga ruxsat bermasa — test uchun O'zbekistonda server olinadi.

**panel.1call.uz — andoza (qaror #10, #45):** u yerdagi qo'ng'iroq boshqaruvi funksiyalari (trunklar, kanallar limiti, navbatlar, monitoring, yozuvlar) va dialplan mantig'i ibTaxi PBX paneli uchun namuna sifatida olinadi; 1call'dan hech narsa ko'chirilmaydi va unga ulanmaydi.

> ⚠️ Bitta SIP akkaunt bir vaqtda faqat bitta serverda ro'yxatdan o'tishi kerak — bitta login ikki serverda ulansa, qo'ng'iroqlar tasodifiy taqsimlanadi. Test va production uchun alohida akkaunt.

### 5.2a Telefon raqamlari ↔ filiallar (qaror #15)

Raqamlar soni va bog'lanishi kodda qattiq yozilmaydi — superadmin panelda sozlaydi. Uchala holat ham qo'llab-quvvatlanadi:

| Holat                                 | Misol                                                                                       |
| ------------------------------------- | ------------------------------------------------------------------------------------------- |
| **Bitta raqam → hamma filial**        | `+998 71 200 00 00` — butun kompaniya uchun yagona raqam                                    |
| **Har filialga alohida raqam**        | Toshkent → `71 200 00 00`, Samarqand → `66 200 00 00`                                       |
| **Bitta raqam → tanlangan filiallar** | `71 200 00 00` → Toshkent shahri + Toshkent viloyati; Samarqand o'z raqamida                |
| Aralash                               | Bir vaqtda yuqoridagilarning har qandayi; bir filialda bir nechta raqam ham bo'lishi mumkin |

**Ma'lumotlar modeli:** `phone_numbers` (raqam, trunk, nomi, routing rejimi, faol) ↔ `branch_phone_number` (ko'pga-ko'p).

**Bitta raqam bir nechta filialga bog'langanda qo'ng'iroq qayerga ketadi** — raqam sozlamasida tanlanadi (`routing_mode`):

1. **IVR menyu** — "Toshkent uchun 1 ni, Samarqand uchun 2 ni bosing" → tanlangan filial navbati. Menyu bo'laklari filial nomlaridan yig'iladi (filial nomi audio bo'lagi kerak)
2. **Umumiy navbat (markaziy call-markaz)** — bog'langan filiallarning barcha operatorlari bitta navbatda; operator buyurtma formasida filialni tanlaydi (mijozning oxirgi filiali avtomatik tanlangan bo'ladi)
3. **Mijoz tarixi bo'yicha avtomatik** — raqam bazada bo'lsa → oxirgi buyurtma qilgan filial navbatiga; yangi mijoz bo'lsa → 1-variant (IVR) yoki 2-variant (umumiy navbat)

**Filial hududi (qaror #31):** filial yaratishda admin **viloyat → shahar yoki tuman** tanlaydi (masalan Farg'ona viloyati → Marg'ilon shahri, yoki Andijon viloyati → Asaka tumani). Tanlanganda xarita markazi va **xizmat hududi** (`service_area`) shu shahar/tuman chegarasidan avtomatik to'ldiriladi (OSM ma'muriy chegaralari); admin xaritada chegarani qo'lda kengaytirishi/toraytirishi mumkin. Bitta filial bir nechta tuman/shaharni qamrashi ham mumkin.

**Umumiy navbatda filialni avtomatik aniqlash:** har filialning **xizmat hududi** xaritada chiziladi (`branches.service_area` — polygon). Operator olib ketish nuqtasini belgilagach, nuqta qaysi filial hududiga tushsa — o'sha filial avtomatik tanlanadi (operator o'zgartira oladi).

**Boshlang'ich holat:** Sarkor'da **1 ta raqam, 4 ta kanal** (bir vaqtda 4 ta qo'ng'iroq). Raqam panelda kiritiladi va filial(lar)ga biriktiriladi. Yangi raqamlar keyin qo'shilsa — kod o'zgarmaydi, faqat panelda sozlanadi.

**Chiquvchi qo'ng'iroqlarda Caller ID** (TTS xabarlar, operator callback): mijoz qaysi raqamga qo'ng'iroq qilgan bo'lsa — o'sha raqamdan; topilmasa — filialning asosiy raqamidan. Mijoz qayta qo'ng'iroq qilsa, to'g'ri joyga tushadi.

**Ta'siri boshqa qismlarga:**

- Operator bir nechta filialga biriktirilishi mumkin (`branch_user` ko'pga-ko'p) — umumiy navbat rejimi uchun. Operator faqat o'zi biriktirilgan filiallarni ko'radi
- Buyurtmada `phone_number_id` saqlanadi — statistika "qaysi raqamdan qancha buyurtma" ko'rinishida
- Parallel liniyalar limiti (§5.3) raqam emas, trunk va filial darajasida qoladi

### 5.3 Parallel liniyalar — admin panelda sozlanadi (qaror #13)

**Sozlamalar (bazada, panel orqali o'zgartiriladi):**

| Daraja | Maydon                                                   | Misol                                                  |
| ------ | -------------------------------------------------------- | ------------------------------------------------------ |
| Trunk  | `max_channels` — Sarkor bergan jami liniyalar            | **4** (hozirgi Sarkor)                                 |
| Trunk  | `max_inbound`, `max_outbound` (ixtiyoriy)                | 4 / 1                                                  |
| Filial | `max_channels` — shu filialga ajratilgan ulush           | 4 (bitta filial bo'lsa)                                |
| Filial | `max_auto_calls` — TTS avto-qo'ng'iroqlar uchun maksimal | 1 (operator liniyalarini band qilib qo'ymasligi uchun) |

> ⚠️ **1000+ buyurtma/kun uchun 4 kanal yetmaydi** — hisob §9.3 da. Tavsiya: firmaga 1000+ buyurtma/kun bo'lsa, ishga tushirishdan oldin provayderdan **kamida 8–10 kanal** olish — qo'shimcha kanal narxi **60 000 so'm** (Sarkor). Panelda faqat raqam o'zgaradi. Monitoring va statistikada "kanal bandligi sababli yo'qotilgan qo'ng'iroqlar" kuzatiladi.

**Qisqa raqam (qaror #41):** Sarkor'dan olingan qisqa raqam (1XXX) Asterisk'ga oddiy kiruvchi raqam (DID) bo'lib keladi — panelda "Telefon raqamlari"ga qo'shiladi va filial(lar)ga biriktiriladi (§5.2a). Chiquvchi avto-qo'ng'iroqlarda Caller ID sifatida qisqa raqam ko'rinishi Sarkor bilan alohida tekshiriladi (ba'zi operatorlar qisqa raqamni chiquvchi uchun ko'rsatmaydi).

**Qanday ishlaydi:**

- Dialplan har qo'ng'iroqda Asterisk `GROUP()` / `GROUP_COUNT()` bilan joriy band liniyalarni sanaydi; limit bazadan o'qiladi (`func_odbc` yoki Realtime) — panelda o'zgartirilsa **darhol** kuchga kiradi, Asterisk qayta ishga tushirilmaydi
- **Kiruvchi limitdan oshsa:** "Hozir barcha liniyalar band, iltimos keyinroq qo'ng'iroq qiling" xabari yoki band signali (panelda tanlanadi); qo'ng'iroq "javobsiz" ro'yxatiga tushadi → operator qayta qo'ng'iroq qiladi
- **Chiquvchi (TTS, callback) limitdan oshsa:** rad etilmaydi — Laravel navbatida kutadi va liniya bo'shashi bilan yuboriladi
- **Jonli monitoringda:** har trunk va filial uchun "band / limit" ko'rsatkichi (masalan `4 / 10`), limitga yetganda ogohlantirish
- **Statistika:** kun/soat bo'yicha eng ko'p band bo'lgan liniyalar soni va "liniya bandligi sababli yo'qotilgan qo'ng'iroqlar" — qachon Sarkor'dan qo'shimcha liniya olish kerakligini ko'rsatadi

### 5.4 Avtomatik ovozli xabarlar (TTS)

**Qachon qo'ng'iroq qilinadi:**

| Hodisa                                                        | Xabar namunasi                                                        |
| ------------------------------------------------------------- | --------------------------------------------------------------------- |
| Haydovchi tayinlandi (qaror #16)                              | "Sizga oq Cobalt, raqami 01A123BC, taxminan 5 daqiqada yetib keladi"  |
| Haydovchi yetib keldi                                         | "Sizni haydovchi kutmoqda. Oq Cobalt, 01A123BC"                       |
| Haydovchi hali kutmoqda (2–3 daqiqadan keyin, mijoz chiqmasa) | "Haydovchi hali ham sizni kutmoqda…"                                  |
| Haydovchi topilmadi                                           | "Kechirasiz, hozir bo'sh mashina yo'q. Operator siz bilan bog'lanadi" |
| Buyurtma bekor qilindi (operator/haydovchi tomonidan)         | "Buyurtmangiz bekor qilindi…"                                         |

**Qanday ishlaydi:**

1. Laravel hodisasi (`DriverArrived` va h.k.) → queue job → ARI orqali Asterisk'ga outbound qo'ng'iroq buyrug'i
2. Mijoz javob bergach Asterisk audio faylni ijro etadi (xabar 2 marta takrorlanadi)
3. Javob bermasa / band bo'lsa: 30 soniyadan keyin 1–2 marta qayta urinish, so'ng operator paneliga "mijozga yetkazilmadi" belgisi
4. Natija (`answered`, `no_answer`, `busy`) `calls` jadvaliga yoziladi va buyurtmaga bog'lanadi

**Audio — oldindan generatsiya qilingan bo'laklardan yig'iladi (qaror #14):**

- Barcha bo'laklar **bir marta**, **ayol ovozida** generatsiya qilinadi va serverda saqlanadi
- Asterisk ularni ketma-ket ijro etadi: `sizni-haydovchi-kutmoqda` + `oq` + `cobalt` + `nol` + `bir` + `a` + `bir-yuz` + `yigirma` + `uch` + `be` + `se`
- Real vaqtda TTS chaqirilmaydi → kechikish yo'q, internet/API'ga bog'liq emas, oylik to'lov yo'q
- Mijozga ovozli xabarlar **o'zbek tilida** (panel tili rus bo'lsa ham). Butun tizimda **bitta ovoz** — barcha xabarlar bir xil ovozda (SpeechKit ovozi superadmin sozlamasida tanlanadi: zamira / yulduz / nigora)

**Fayl tuzilmasi va format:**

```
storage/app/sounds/uz/
  phrases/   sizni-haydovchi-kutmoqda.wav, …
  numbers/   0.wav … 19.wav, 20.wav, 30.wav … 90.wav, 100.wav (bir-yuz), 200.wav …
  letters/   a.wav … z.wav
  colors/    oq.wav, qora.wav, …
  cars/      cobalt.wav, nexia.wav, …
```

- Generatsiya: WAV → Asterisk uchun **8 kHz, mono, 16-bit** (`.wav` yoki `.sln`) ga o'giriladi; ovoz balandligi normalizatsiya qilinadi (bo'laklar bir xil balandlikda eshitilishi uchun)
- Bazada `sound_clips` jadvali: `category`, `key`, `text` (nima deyilishi), `file_path`, `duration_ms` — panelda tinglash va qayta yuklash mumkin
- Yetishmayotgan bo'lak (masalan yangi mashina modeli uchun) panelda ⚠️ bilan ko'rsatiladi; ovozli xabar bu bo'lakni o'tkazib yuboradi (xabar baribir yetkaziladi)

**Bo'laklar katalogi (birinchi versiya):**

| Kategoriya            | Tarkibi                                                                                                                                                                                                                                                                                                                                   | Taxminiy soni (1 ovoz) |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| **Jumlalar**          | "Sizni haydovchi kutmoqda", "Haydovchi yo'lga chiqdi", "Sizga … yetib keladi", "taxminan", "daqiqada", "Haydovchi hali ham sizni kutmoqda", "Kechirasiz, hozir bo'sh mashina yo'q", "Operator siz bilan bog'lanadi", "Buyurtmangiz bekor qilindi", "Mashina raqami", "rangli", "Rahmat, oq yo'l!", "Xabarni takrorlash uchun 1 ni bosing" | ~20                    |
| **IVR / navbat**      | "Assalomu alaykum, {xizmat nomi} xizmatiga xush kelibsiz" (xizmat nomi admin panelda kiritiladi — qaror #19), "Barcha operatorlar band, iltimos kuting", "Sizning navbatingiz …", "Hozir ish vaqti emas", "Barcha liniyalar band, keyinroq qo'ng'iroq qiling"                                                                             | ~10                    |
| **Raqamlar**          | 0–19, 20, 30 … 90, 100, 200 … 900 ("bir yuz", "ikki yuz" …), ming                                                                                                                                                                                                                                                                         | ~40                    |
| **Harflar**           | Davlat raqami uchun lotin harflari (A, B, C … Z — o'zbekcha o'qilishi: "a", "be", "se" …)                                                                                                                                                                                                                                                 | ~26                    |
| **Ranglar**           | oq, qora, kumush rang, kulrang, ko'k, havorang, qizil, yashil, sariq, jigarrang, to'q ko'k, …                                                                                                                                                                                                                                             | ~15                    |
| **Mashina modellari** | Cobalt, Nexia, Gentra, Lacetti, Spark, Damas, Matiz, Malibu, Tracker, Onix, Captiva, Epica, Tico, Monza, Kia K5, BYD … (filialda ishlatiladiganlari)                                                                                                                                                                                      | ~30                    |

Jami: **~140 fayl**.

**Davlat raqamini o'qish qoidasi** (`PlateSpeaker` servis + testlar):

- Jismoniy shaxs: `01 A 123 BC` → "nol bir" · "a" · "bir yuz yigirma uch" · "be" · "se"
- Yuridik shaxs: `01 123 ABC` → "nol bir" · "bir yuz yigirma uch" · "a" · "be" · "se"
- Viloyat kodi raqamma-raqam ("nol bir"), asosiy raqam son sifatida ("bir yuz yigirma uch") — panelda o'zgartirsa bo'ladigan qoida

**TTS xizmati — Yandex SpeechKit (qaror #17):**

- O'zbek tili (`uz-UZ`) ovozlari: **zamira** (rollar: neutral / strict / friendly), **yulduz**, **nigora** — barchasi **ayol** ovozi
- Integratsiya: `php artisan sounds:generate` — katalogdagi har bo'lak uchun SpeechKit API'ga so'rov → WAV → 8 kHz mono'ga o'girish → `sound_clips` ga yozish. Faqat yangi/o'zgargan bo'laklar qayta generatsiya qilinadi
- Panelda yangi bo'lak (masalan yangi mashina modeli) qo'shilganda ham shu servis chaqiriladi
- **Salomlashuv (qaror #19):** admin filial sozlamalarida **xizmat nomi**ni kiritadi (masalan "Ibrat taksi") va salomlashuv matnini ko'radi/tahrirlaydi. Saqlanganda butun jumla ("Assalomu alaykum, Ibrat taksi xizmatiga xush kelibsiz") **bir butun audio** sifatida generatsiya qilinadi — bo'laklardan yig'ilmaydi, tabiiyroq eshitiladi. Admin tinglab ko'radi, yoqmasa qayta generatsiya qiladi yoki o'z faylini yuklaydi
- Xarajat: ~140 bo'lak, qisqa matnlar — bir martalik va juda kichik summa (SpeechKit belgilar soni bo'yicha hisoblaydi)
- Generatsiyadan keyin har bir bo'lakni **eshitib tekshirish** shart (urg'u, talaffuz) — panelda "tinglash / qayta generatsiya / o'z faylini yuklash" tugmalari

### 5.5 PBX boshqaruv paneli (ibTaxi ichida, panel.1call.uz andozasida)

Alohida PBX panel yo'q — hammasi ibTaxi admin panelining "Telefoniya" bo'limida.

| Modul                           | Imkoniyatlar                                                                                                                                                               | Kim ko'radi                                   |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| **SIP trunklar**                | Provayder qo'shish (host, login, parol, kodeklar), holati (ulangan/uzilgan), **parallel liniyalar limiti** (§5.3)                                                          | superadmin                                    |
| **Telefon raqamlari**           | Raqam qo'shish, trunkka bog'lash, filial(lar)ga bog'lash (bitta / bir nechta / hammasi), routing rejimi (IVR / umumiy navbat / mijoz tarixi), asosiy raqam belgisi (§5.2a) | superadmin                                    |
| **Liniyalar limiti (filial)**   | Filial uchun maksimal parallel qo'ng'iroqlar; avto-qo'ng'iroqlar (TTS) uchun alohida ulush                                                                                 | superadmin → filialga ajratadi, admin ko'radi |
| **Ichki raqamlar (extensions)** | Har operatorga va **har haydovchiga** (dispetcherga qo'ng'iroq uchun, qaror #47) avtomatik WebRTC raqam, parol avtomatik; foydalanuvchi yaratilganda o'zi yaratiladi       | admin                                         |
| **Navbatlar (queues)**          | Filial navbati, strategiya, kutish vaqti, kutish musiqasi, operatorlarni biriktirish                                                                                       | admin                                         |
| **IVR / ish vaqti**             | Salomlashuv audio, ish vaqti jadvali, ishdan tashqari xabar, bayram kunlari                                                                                                | admin                                         |
| **Audio fayllar**               | Salomlashuv, kutish musiqasi, TTS bo'laklarini yuklash/generatsiya qilish, tinglash                                                                                        | admin                                         |
| **Jonli monitoring**            | Hozir kim gaplashyapti, navbatda nechta qo'ng'iroq kutyapti, operatorlar holati (bo'sh/band/tanaffus), real-time Reverb orqali                                             | admin, operator (o'z holati)                  |
| **Qo'ng'iroqlar tarixi**        | Filtr (sana, operator, raqam, holat), yozuvni tinglash/yuklab olish, buyurtmaga havola                                                                                     | admin                                         |
| **Statistika**                  | Javob berilgan/javobsiz, o'rtacha kutish, o'rtacha suhbat, operatorlar bo'yicha, soatlar bo'yicha yuklama                                                                  | admin, superadmin                             |
| **Qora ro'yxat**                | Raqamni bloklash (Asterisk darajasida rad etiladi)                                                                                                                         | admin                                         |

---

## 5b. Narx hisoblash (qaror #21)

**Formula:**

```
agar masofa ≤ kiritilgan_km:   narx = boshlang'ich_narx
aks holda:                     narx = boshlang'ich_narx + (masofa − kiritilgan_km) × km_narxi
```

**Misol** (qiymatlar shartli — admin panelda o'rnatiladi):

| Sozlama                       | Qiymat      |
| ----------------------------- | ----------- |
| Boshlang'ich narx             | 10 000 so'm |
| Boshlang'ich narx ichidagi km | 3 km        |
| Har keyingi km                | 2 000 so'm  |

- 2.5 km yo'l → **10 000 so'm**
- 7.4 km yo'l → 10 000 + (7.4 − 3) × 2 000 = 18 800 → yaxlitlangandan keyin **19 000 so'm**

**Qanday ishlaydi:**

- **Taxminiy narx** (operator buyurtma berganda, agar borish manzili aytilgan bo'lsa): OSRM yo'l masofasi bo'yicha — operator mijozga aytadi
- **Yakuniy narx** — haydovchi ilovasidagi **taksometr**: GPS bo'yicha bosib o'tilgan haqiqiy km (`IN_PROGRESS` dan `COMPLETED` gacha). Ekranda jonli ko'rinib turadi: km va joriy narx
- Masofa hisobi telefonda (internet uzilsa ham ishlaydi), yakunlanganda serverga yuboriladi; server GPS treki bo'yicha qayta tekshiradi (firibgarlikka qarshi — katta farq bo'lsa adminga belgi)
- GPS sakrashlari filtrlanadi (aniqligi past nuqtalar, imkonsiz tezlik) — aks holda masofa sun'iy oshadi
- Hisob-kitob logikasi bitta joyda: backend `FareCalculator` servisi (testlar bilan); Android ilova ham xuddi shu formulani ishlatadi, sozlamalar API orqali keladi

**To'lov — faqat naqd (qaror #22):** mijoz haydovchiga naqd to'laydi; ilova yakunda katta shriftda summani ko'rsatadi, haydovchi "To'landi" bosadi. Kompaniya ulushi (komissiya) haydovchi balansidan yechiladi (§5c).

### Tarif klasslari (qaror #27)

O'rnatishda 4 ta klass tayyor yaratiladi, admin panelda tahrirlanadi / o'chiriladi / yangisi qo'shiladi:

| Klass        | Mashinalar (misol)                            | Narx          |
| ------------ | --------------------------------------------- | ------------- |
| **Standart** | Cobalt, Nexia, Gentra, Spark                  | o'z sozlamasi |
| **Komfort**  | Malibu, Onix, Tracker, Kia K5                 | o'z sozlamasi |
| **Yuk**      | Damas, Labo, Gazel                            | o'z sozlamasi |
| **Dostavka** | har qanday mashina (posilka/hujjat yetkazish) | o'z sozlamasi |

- Har klassning **o'z narxi**: boshlang'ich narx, ichidagi km, km narxi (yuqoridagi formula)
- **Mashina ↔ klass — ko'pdan-ko'p:** admin har mashinaga qaysi klasslarda ishlashini belgilaydi (Malibu → Komfort + Standart; Damas → Yuk + Dostavka)
- Operator buyurtmada klassni tanlaydi (standart: **Standart**) → dispatcher faqat shu klassga ruxsati bor mashinalarga taklif yuboradi
- Haydovchi ilovasida taklifda klass ko'rinadi (masalan "📦 Dostavka")
- **Dostavka buyurtmasi uchun qo'shimcha maydonlar:** jo'natuvchi, qabul qiluvchi ismi va telefoni, nima yetkaziladi (izoh). Avto-qo'ng'iroqlar: "yetib keldi" — jo'natuvchiga; ixtiyoriy "yetkazildi" — qabul qiluvchiga

### Qo'shimcha narx qoidalari — panelda yoqiladi/o'chiriladi (qaror #33)

To'liq formula:

```
narx = boshlang'ich_narx
     + max(0, shahar_ichidagi_km − kiritilgan_km) × km_narxi
     + shahar_tashqarisidagi_km × tashqi_km_narxi          ← agar yoqilgan bo'lsa
     + max(0, kutish_daqiqasi − bepul_daqiqa) × daqiqa_narxi ← agar yoqilgan bo'lsa
narx = narx × tungi_koeffitsient                            ← agar yoqilgan va tungi vaqt bo'lsa
narx = yaxlitlash(narx, qadam)
```

| Qoida                 | Sozlamalar (filial va klass bo'yicha)                                              | Qanday hisoblanadi                                                                                                   |
| --------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **Kutish puli**       | yoqish/o'chirish, bepul daqiqa (masalan 3), har daqiqa narxi                       | Haydovchi "Yetib keldim" bosgandan "Yo'lovchi o'tirdi"gacha bo'lgan vaqt; ilovada jonli taymer ko'rinadi             |
| **Tungi narx**        | yoqish/o'chirish, vaqt oralig'i (masalan 23:00–06:00), koeffitsient (masalan ×1.2) | Buyurtma **boshlangan** vaqt bo'yicha (filial vaqt mintaqasida)                                                      |
| **Shahar tashqarisi** | yoqish/o'chirish, tashqi km narxi                                                  | Taksometr GPS nuqtasi filial **xizmat hududi**dan (`service_area`, qaror #31) chiqqandan keyingi km alohida sanaladi |

- Haydovchi ilovasida yakuniy chek: boshlang'ich narx, km, shahar tashqarisi km, kutish, tungi ustama — alohida qatorlarda
- O'chirilgan qoida formulada umuman qatnashmaydi

**Admin panelda (filial va klass bo'yicha):** boshlang'ich narx, ichidagi km, km narxi, yaxlitlash qadami (standart **500 so'm** — qaror #34) va yuqoridagi qo'shimcha qoidalar. O'zgarishlar faqat **yangi** buyurtmalarga ta'sir qiladi — buyurtmada tarif qiymatlari nusxasi saqlanadi.

---

## 5c. Haydovchi balansi va komissiya (qaror #23)

**Model:** haydovchi balansni **oldindan** to'ldiradi → har yakunlangan buyurtmadan **foiz** (komissiya) balansdan yechiladi → balans yetarli bo'lsa buyurtma oladi.

```
komissiya = yakuniy_narx × foiz      (masalan 20 000 so'm × 10% = 2 000 so'm)
```

**Balansni to'ldirish usullari:**

| Usul                     | Qanday                                                                                                                    | Texnik                                                                                                                                                |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Click**                | Haydovchi Click ilovasida "ibTaxi" xizmatini topadi, o'z **haydovchi ID**sini (pozivnoy/raqam) kiritadi, summani to'laydi | Click **SHOP API** (Prepare/Complete callback'lari) — `/api/payments/click/*`                                                                         |
| **Payme**                | Xuddi shunday, Payme ilovasida ID bo'yicha                                                                                | Payme **Merchant API** (JSON-RPC: `CheckPerformTransaction`, `CreateTransaction`, `PerformTransaction`, `CancelTransaction`…) — `/api/payments/payme` |
| **Firma kassasi (naqd)** | Haydovchi ofisga keladi, **kassir** panelda kirim qiladi                                                                  | Kassir paneli: "Balansni to'ldirish" — kim, qachon, qancha (audit log); kassa smenasi hisoboti                                                        |

- Click/Payme to'lovi kelganda: ID tekshiriladi → balansga qo'shiladi → haydovchi ilovasiga push "Balansingiz +50 000 so'm" (darhol)
- Ikkala tizim uchun ham **bir xil to'lov ikki marta hisoblanmasligi** (idempotentlik) va bekor qilingan to'lovni qaytarish (`CancelTransaction`) — testlar bilan
- Shartnoma: har bir xaridor firma Click va Payme bilan **o'z nomidan** merchant shartnoma tuzadi va kalitlarini panelga kiritadi (§0). Shartnoma bo'lmasa — faqat kassa orqali to'ldirish

**Buyurtma olish sharti:**

- Haydovchi balansi **≥ minimal balans** (admin panelda sozlanadi, standart **5 000 so'm** — qaror #34) bo'lsa — buyurtma taklif qilinadi
- Kam bo'lsa — dispatcher unga taklif yubormaydi; ilovada "Balans yetarli emas, to'ldiring" va to'ldirish yo'riqnomasi (ID, Click/Payme tugmalari — deep-link)
- Komissiya buyurtma **yakunlanganda** yechiladi; balans manfiyga tushishi mumkin (oxirgi buyurtma), keyingi buyurtmani esa olmaydi

**Admin panelda (filial bo'yicha):** komissiya foizi (standart **10%**), minimal balans (standart 5 000 so'm); haydovchi balans tarixi (`driver_transactions`), qo'lda tuzatish (bonus/jarima — izoh majburiy), kunlik hisobot: jami komissiya, Click/Payme/kassa bo'yicha tushum.

**Haydovchi ilovasida:** joriy balans, tarix (to'ldirishlar, komissiyalar), "Balansni to'ldirish" ekrani (ID nusxalash, Click/Payme'ni ochish).

---

## 6. Buyurtma tarqatish — "efir" (qaror #28)

**Usul:** buyurtma radius ichidagi **barcha** mos bo'sh haydovchilarga bir vaqtda ko'rsatiladi — **kim birinchi qabul qilsa, o'sha oladi**.

**Qadamlar:**

1. Haydovchi ilovasi har **3–5 soniyada** (harakatda) yoki 30 soniyada (turganda) koordinata yuboradi → `drivers.last_location` (PostGIS, qaror #48) + `driver_locations` tarixi
2. Buyurtma kelganda: `ST_DWithin` radius R (boshlanishi 3 km — panelda sozlanadi) ichidagi bo'sh haydovchilar
3. Filtrlash: mashina buyurtmadagi tarif klassiga ruxsatli (§5b), haydovchi bloklanmagan, balansi ≥ minimal balans (§5c), oxirgi joylashuvi 60 soniyadan eski emas
4. **Efir:** barchasiga bir vaqtda taklif — Reverb + FCM (qaror #1). Taklifda: olib ketish manzili, haydovchidan **masofa** (har haydovchiga o'zinikini), klass, taxminiy narx (agar borish manzili bo'lsa)
5. Birinchi "Qabul qildim" → buyurtma unga biriktiriladi → **qolgan haydovchilarda taklif darhol yo'qoladi** ("Buyurtmani boshqa haydovchi oldi")
6. **20 soniyada** (panelda sozlanadi) hech kim olmasa → R kengayadi (3 → 5 → 8 km), yangi qo'shilgan haydovchilarga ham efir; eski efirdagilarda taklif qoladi
7. Baribir hech kim yo'q → operatorga "Haydovchi topilmadi" ogohlantirishi + **qo'lda tayinlash** + mijozga TTS ("Kechirasiz, hozir bo'sh mashina yo'q…")

**"Kim birinchi" — poyga holati (race condition) himoyasi — eng muhim joy:**

- Qabul qilish **atomik** amal: `UPDATE orders SET driver_id = ?, status = 'ASSIGNED' WHERE id = ? AND status = 'SEARCHING'` — faqat bitta so'rov muvaffaqiyatli bo'ladi (1 qator yangilandi); qolganlar "kech qoldingiz" javobini oladi
- Shu tranzaksiya ichida haydovchi ham `busy` qilinadi (`... WHERE id = ? AND status = 'free'`) — bir haydovchi bir vaqtda ikki efirdan ikkita buyurtma ola olmaydi
- Testlar: parallel 10 ta "qabul qilish" so'rovi → faqat 1 tasi yutadi (Pest, haqiqiy PostgreSQL bilan)

**Adolatlilik — efirning zaif tomoni va yechimlar:**
Efirda tezroq bosgan (yoki internetni tezroq) haydovchi yutadi, eng yaqini emas. Mijoz uzoqroq kutishi mumkin. Shuning uchun:

- Efir radiusi kichik boshlanadi (3 km) — taklif faqat yaqinlarga boradi
- Taklifda haydovchining masofasi ko'rinadi — uzoqdagi haydovchi o'zi baholaydi
- **Ixtiyoriy (panelda yoqiladi):** "eng yaqinlarga ustunlik" — birinchi 5 soniya taklif faqat eng yaqin 3 ta haydovchiga, keyin hammaga

**Rad etish — jarima va reyting (qaror #29):**

Efirda taklifni olmaslik — oddiy holat (jarimasiz), aks holda efirni ko'rgan hamma jarimaga tushardi. Jarima quyidagilar uchun:

| Holat                                                                          | Jarima (balansdan)                         | Reyting |
| ------------------------------------------------------------------------------ | ------------------------------------------ | ------- |
| Buyurtmani **qabul qilib, keyin bekor qildi**                                  | ✅ masalan 2 000 so'm                      | ↓       |
| Operator **qo'lda tayinlagan** buyurtmani rad etdi / 15 soniyada javob bermadi | ✅                                         | ↓       |
| Yetib kelgach mijozsiz "bekor" qildi (mijoz chiqmadi)                          | ❌ — sababi tanlanadi, operator tekshiradi | —       |

- Bekor qilishda haydovchi **sababni tanlaydi** (mashina buzildi, mijoz javob bermadi, …); "uzrli" sabablarni admin keyin ko'rib, jarimani bekor qilishi mumkin
- **Reyting:** 0–100 ball (yoki 5 yulduz) — bajarilgan buyurtmalar oshiradi, bekor qilish va rad etish pasaytiradi. Reyting past bo'lsa — efirda keyinroq ko'radi (masalan +5 soniya kechikish) yoki yaqin radiusga kirmaydi
- **Vaqtincha bloklash:** bir smenada N marta bekor qilsa (masalan 3) → M daqiqaga efirdan chiqariladi (masalan 30 daqiqa)
- **Panelda sozlanadi (filial bo'yicha):** jarima summasi, reyting ta'siri, N va M, jarimani yoqish/o'chirish
- Barcha jarimalar `driver_transactions` da (`type = penalty`, sabab bilan) — haydovchi ilovada ko'radi

**Statistika (panelda):** buyurtma necha soniyada olindi, efirda nechta haydovchi ko'rdi, haydovchi qabul qilgan masofa — radius va vaqt sozlamalarini to'g'rilash uchun.

---

## 7. Ma'lumotlar bazasi (asosiy jadvallar)

| Jadval                 | Asosiy maydonlar                                                                                                                                                                                                                                                                              |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `regions`              | id, parent_id, type (viloyat / shahar / tuman), name_uz, name_ru, center, boundary (geography polygon, OSM'dan) — O'zbekiston ma'muriy bo'linishi, o'rnatishda seed qilinadi                                                                                                                  |
| `branches`             | id, name, service_name (IVR uchun xizmat nomi), greeting_text, region_id (shahar/tuman — qaror #31), center (geography point), service_area (geography polygon), timezone, settings (json)                                                                                                    |
| `users`                | id, branch_id (asosiy filial), name, phone, password, locale (uz/ru — qaror #44), is_active — rollar spatie orqali                                                                                                                                                                            |
| `branch_user`          | branch_id, user_id — operatorni bir nechta filialga biriktirish (umumiy navbat uchun)                                                                                                                                                                                                         |
| `phone_numbers`        | id, trunk_id, number (E.164), name, routing_mode (ivr/shared_queue/client_history), fallback_mode, is_active                                                                                                                                                                                  |
| `branch_phone_number`  | branch_id, phone_number_id, is_primary, ivr_digit                                                                                                                                                                                                                                             |
| `drivers`              | id, user_id, branch_id, callsign (pozivnoy — butun nusxada unique; Click/Payme to'lovida ID sifatida ishlatiladi), status (offline/free/busy), balance, rating, blocked_until, license_no                                                                                                     |
| `order_cancellations`  | order_id, driver_id, reason, penalty_amount, penalty_waived_by, created_at                                                                                                                                                                                                                    |
| `vehicles`             | id, driver_id (egasi — haydovchi), brand, model, color, plate_number (unique), year, tech_passport_no, photos, is_approved, approved_by — haydovchining o'z mashinasi (qaror #26)                                                                                                             |
| `driver_documents`     | driver_id, type (prava / tex-pasport / pasport / mashina rasmi), file_path, expires_at, status (kutilmoqda/tasdiqlandi/rad etildi)                                                                                                                                                            |
| `driver_locations`     | driver_id, location (geography), speed, heading, recorded_at — **tarix** (partitsiyalangan, 30–90 kun)                                                                                                                                                                                        |
| `clients`              | id, last_branch_id, phone (unique — butun nusxa bo'yicha, chunki 1 nusxa = 1 firma), name, note, is_blacklisted, orders_count                                                                                                                                                                 |
| `client_addresses`     | client_id, label ("Uy"), landmark_id / location, text                                                                                                                                                                                                                                         |
| `landmarks`            | id, region_id (filiallar o'rtasida umumiy — qo'shni filiallar bir xil mo'ljallardan foydalanadi), name, aliases (text[]), location, category, usage_count                                                                                                                                     |
| `tariff_classes`       | id, branch_id, name (Standart/Komfort/Yuk/Dostavka/…), icon, sort, is_delivery (dostavka maydonlari kerakmi), is_default, is_active                                                                                                                                                           |
| `tariffs`              | id, branch_id, tariff_class_id, base_fare, base_km (boshlang'ich narx ichidagi km), per_km, rounding_step (standart 500), waiting_enabled, waiting_free_min, waiting_per_min, night_enabled, night_from, night_to, night_coeff, outside_enabled, outside_per_km, is_active                    |
| `vehicle_tariff_class` | vehicle_id, tariff_class_id — mashina qaysi klasslarda ishlaydi                                                                                                                                                                                                                               |
| `order_deliveries`     | order_id, sender_name, sender_phone, recipient_name, recipient_phone, description — faqat Dostavka buyurtmalari uchun                                                                                                                                                                         |
| `orders`               | id, branch_id, client_id, operator_id, driver_id, tariff_id, status, pickup_text, pickup_location, dropoff_text, dropoff_location, comment, tariff_snapshot (json — buyurtma paytidagi tarif qiymatlari), price_estimate, price_final, distance_m, duration_s, call_id, timestamps per status |
| `order_offers`         | order_id, driver_id, offered_at, distance_m, response (accepted/too_late/ignored/rejected), responded_at — efirda kimga ko'rsatildi va kim olgan                                                                                                                                              |
| `order_events`         | order_id, type, actor_id, payload (json), created_at                                                                                                                                                                                                                                          |
| `driver_transactions`  | driver_id, order_id, type (commission/topup/penalty/bonus/adjustment), amount, balance_after, source (click/payme/cash/system), created_by, comment                                                                                                                                           |
| `payment_transactions` | provider (click/payme), provider_transaction_id (unique), driver_id, amount, state, raw_request (json), timestamps — Click/Payme callback'lari uchun                                                                                                                                          |
| `chat_messages`        | id, driver_id, order_id (ixtiyoriy), sender_id, body, attachment_path, read_at, created_at — haydovchi ↔ dispetcher chati (qaror #47)                                                                                                                                                         |
| `calls`                | id, uniqueid (Asterisk), direction, caller, operator_id, order_id, duration, recording_path, status                                                                                                                                                                                           |
| `sound_clips`          | id, category (phrase/number/letter/color/car/ivr), key, text, file_path, duration_ms                                                                                                                                                                                                          |
| `cash_shifts`          | id, branch_id, cashier_id, opened_at, closed_at, total_cash, admin_confirmed_by — kassir smenasi                                                                                                                                                                                              |
| `shifts`               | operator yoki haydovchi smenasi: started_at, ended_at                                                                                                                                                                                                                                         |

---

## 8. Ilovalar va panellar (modullar)

### 8.1 Operator paneli (web, eng muhim ekran)

- Chap: **faol buyurtmalar** ro'yxati (status rangi bilan, taymer)
- O'rta: **xarita** — bo'sh (yashil) / band (qizil) / oflayn haydovchilar, buyurtma nuqtalari
- O'ng: **yangi buyurtma formasi** (qo'ng'iroq kelganda avtomatik ochiladi, telefon to'ldirilgan)
- Pastda/tepada: **softphone** (holat: Tayyor / Suhbatda / Tanaffus)
- **Haydovchilar chati** (qaror #47): ro'yxat, o'qilmaganlar soni, buyurtmaga bog'langan suhbatlar; haydovchidan kelgan qo'ng'iroq haydovchi va buyurtma ma'lumoti bilan ochiladi
- Til: o'zbek / rus (qaror #44)
- Klaviatura yorliqlari (tez ishlash uchun — operator sekundlarni hisoblaydi)
- Mo'ljal qidiruvi: 2–3 harf yozilganda takliflar

### 8.2 Admin paneli (filial)

- Haydovchilar (CRUD, bloklash, balans tuzatish)
- **Haydovchini ro'yxatdan o'tkazish (qaror #26):** shaxsiy ma'lumot + o'z mashinasi (marka, model, rang, davlat raqami, yili) + hujjatlar (prava, tex-pasport, mashina rasmlari) → admin tasdiqlaydi → shundan keyingina liniyaga chiqa oladi. Hujjat muddati tugashiga yaqin ogohlantirish
- Haydovchi mashinasini almashtirsa — yangi mashina qayta tasdiqlanadi (eski buyurtmalarda eski mashina ma'lumoti saqlanadi)
- Operatorlar
- Tarif klasslari va narxlar (klass qo'shish/tahrirlash, har klass narxi, mashinalarni klasslarga biriktirish)
- Mo'ljallar bazasi (tahrirlash, birlashtirish)
- Mijozlar (qora ro'yxat)
- Hisobotlar: buyurtmalar, daromad, komissiya, operator KPI, qo'ng'iroqlar, bekor qilish sabablari
- Qo'ng'iroq yozuvlarini tinglash

### 8.3 Superadmin paneli

- Filiallar, adminlar, global sozlamalar, barcha filiallar bo'yicha hisobot, audit log

### 8.4 Haydovchi ilovasi (faqat Android — qaror #43)

- Login: telefon raqami + admin bergan parol (SMS kod yo'q)
- "Liniyaga chiqish / chiqish" tugmasi
- Buyurtma taklifi: to'liq ekran, ovozli signal, taymer, masofa, manzil, taxminiy narx → Qabul / Rad
- Buyurtma bosqichlari: Yo'ldaman → Yetib keldim → Yo'lovchi o'tirdi → Yakunlash
- **Taksometr**: GPS asosida km → narx (boshlang'ich narx + km, §5b), oflayn ham ishlaydi (internet uzilsa)
- Ilova ichidagi xarita (MapLibre): joylashuv, A/B nuqtalar, marshrut chizig'i, masofa va ETA; qo'shimcha: "Navigatorda ochish" tugmasi
- Mijozga qo'ng'iroq — telefonning oddiy qo'ng'iroq oynasi orqali (qaror #42)
- **Dispetcher bilan aloqa (qaror #47):** "Dispetcherga qo'ng'iroq" (ilova ichida WebRTC) + chat (tezkor javoblar, rasm)
- Til: o'zbek / rus (qaror #44)
- Balans, buyurtmalar tarixi, kunlik daromad
- SOS tugmasi (ixtiyoriy)
- Android 10+ (`minSdk 29`, `targetSdk` — eng so'nggi barqaror versiya):
    - Foreground Service (`foregroundServiceType="location"`) — liniyada bo'lganda doimiy bildirishnoma bilan ishlaydi
    - Ruxsatlar: `ACCESS_FINE_LOCATION`, Android 13+ da `POST_NOTIFICATIONS`. Service foreground'da bo'lgani uchun `ACCESS_BACKGROUND_LOCATION` odatda shart emas
    - Buyurtma taklifi uchun to'liq ekranli bildirishnoma (`USE_FULL_SCREEN_INTENT`) + ovozli signal
    - Batareya optimizatsiyasidan chiqarish bo'yicha ilova ichida yo'riqnoma (Xiaomi/Tecno/Infinix "autostart" sozlamalari)

### 8.5 API

- Haydovchi ilovasi uchun REST API (`/api/driver/v1/...`), Laravel Sanctum tokenlari
- Real-time: Reverb (Pusher protokoli) — Android uchun Pusher client kutubxonasi ishlaydi
- Push (FCM, high-priority data message) — har bir taklif Reverb + FCM orqali parallel yuboriladi; ilova `offer_id` bo'yicha takrorni e'tiborsiz qoldiradi
- `device_tokens` jadvali: user_id, platform, fcm_token, updated_at (login va token yangilanganda saqlanadi)

---

## 9. Server infratuzilmasi — ✅ qaror: 1 ta VPS, toza Ubuntu (FastPanel'siz)

Hamma narsa bitta VPS'da (qaror #9), toza Ubuntu LTS, o'rnatish skripti bilan (qaror #46). Har xaridor firma o'z serverini oladi (qaror #36).

| Resurs | Minimal                                 | Tavsiya                                       |
| ------ | --------------------------------------- | --------------------------------------------- |
| CPU    | 4 vCPU                                  | 6–8 vCPU                                      |
| RAM    | 8 GB                                    | 16 GB                                         |
| Disk   | 100 GB SSD/NVMe                         | 200 GB NVMe (qo'ng'iroq yozuvlari tez o'sadi) |
| Tarmoq | Statik IPv4, UDP portlar ochiq bo'lishi | —                                             |

**Serverda nimalar ishlaydi:**

| Xizmat                        | Kim boshqaradi                                    |
| ----------------------------- | ------------------------------------------------- |
| Nginx, PHP-FPM, Laravel       | O'rnatish skripti, systemd                        |
| SSL (Let's Encrypt)           | Certbot (avto-yangilanish + Asterisk reload hook) |
| PostgreSQL + PostGIS, Redis   | Tizim paketlari (apt), systemd                    |
| Reverb, Horizon, `pbx:listen` | Supervisor                                        |
| OSRM, `.pmtiles` tile fayli   | Docker (OSRM), Nginx (tiles)                      |
| **Asterisk, coturn**          | Tizim xizmati (systemd)                           |

**Bitta serverda xavflarni kamaytirish:**

- **Ovoz sifati:** Asterisk'ga yuqori CPU ustuvorligi (`Nice=-10` systemd'da); OSRM va Horizon'ga resurs cheklovi (Docker `--cpus`, Horizon `maxProcesses`). OSRM ma'lumotini qayta qurish (oylik) — tunda
- **Portlar:** SIP (5060/5061), WSS (8089), RTP (10000–20000 UDP), TURN (3478) — `ufw` firewall'da ochiladi (skript o'zi sozlaydi)
- **SSL:** Certbot sertifikati Nginx va Asterisk WSS uchun bir xil; yangilanganda Asterisk'ni reload qiluvchi hook
- **SIP xavfsizligi:** fail2ban, kuchli parollar, SIP portini faqat trunk provayder IP'lariga ochish, operatorlar faqat WebRTC (WSS) orqali
- **Yagona nuqta xavfi:** server tushsa hammasi to'xtaydi → **kundalik zaxira nusxa boshqa joyga** (PostgreSQL dump + yozuvlar, masalan S3-mos saqlash), serverni tezda qayta tiklash uchun yozma yo'riqnoma/skript
- **Monitoring:** Uptime Kuma (tashqi bepul xizmatda yoki boshqa joyda), CPU/RAM/disk ogohlantirishlari Telegram'ga

### 9.3 Yuklama hisobi — 1000+ buyurtma/kun (qaror #32)

Taxminlar: eng tig'iz soatda kunlik buyurtmalarning ~12% (≈ **120 buyurtma/soat**), operator suhbati ~75 soniya, avto-qo'ng'iroq ~20 soniya, har buyurtmaga 1 kiruvchi + 2 avto-qo'ng'iroq.

| Resurs                   | Hisob (tig'iz soat)                                                                                       | Natija                                                                                                            |
| ------------------------ | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Telefon kanallari**    | 120 × 75 s (kiruvchi) + 240 × 20 s (avto) ≈ 3.8 kanal o'rtacha band; cho'qqilar va qayta urinishlar bilan | **8–10 kanal** kerak (hozir 4 — yetmaydi)                                                                         |
| **Operatorlar**          | 120 qo'ng'iroq × 75 s ≈ 2.5 doimiy band operator                                                          | Tig'iz soatda **4–5 operator**, oddiy vaqtda 2–3                                                                  |
| **Haydovchilar**         | O'rtacha safar ~20 daqiqa → soatiga ~2–3 buyurtma/haydovchi                                               | Tig'iz soatda **50–70 faol haydovchi** liniyada                                                                   |
| **GPS oqimi**            | 70 haydovchi × har 4 s                                                                                    | ~18 so'rov/s — Redis uchun juda yengil; tarix PostgreSQL'ga **paket** bilan (har 30 s) yoziladi                   |
| **Qo'ng'iroq yozuvlari** | ~1000 kiruvchi × 1.5 daq × 0.15 MB                                                                        | ~7 GB/oy (§5)                                                                                                     |
| **Ma'lumotlar bazasi**   | ~30 000 buyurtma/oy, `driver_locations` — eng katta jadval                                                | `driver_locations` oylik **partitsiya** + 30–90 kundan keyin o'chirish; indekslar `branch_id, status, created_at` |

**Xulosa:** bitta VPS (§9 tavsiyasi: 6–8 vCPU, 16 GB RAM, 200 GB NVMe) bu hajmni ko'taradi. Asosiy "tor joy" — **telefon kanallari**, server emas.

**Yuklama sinovi (Bosqich 4 dan oldin):** `simulate:drivers` (100 haydovchi) + soxta buyurtmalar generatori (soatiga 200) bilan Daniya test serverida 1 soatlik sinov — dispatcher, Reverb, efir poygasi va DB kechikishini o'lchash.

**Kelajakda:** yuklama oshsa birinchi bo'lib Asterisk alohida VPS'ga ko'chiriladi — arxitektura bunga tayyor (Laravel ↔ Asterisk faqat AMI/ARI tarmoq orqali gaplashadi).

### 9.1 Test serveri — Webdock.io, Daniya (qaror #18)

- Ishlab chiqish va sinov bosqichlari (Bosqich 0–3) shu serverda
- Toza Ubuntu LTS + **o'sha o'rnatish skripti** (qaror #46) — skript har safar shu serverda sinaladi, xaridorga o'rnatish bir xil chiqadi
- **Faqat test ma'lumotlari:** soxta mijozlar, `simulate:drivers` haydovchilari. Haqiqiy mijoz raqamlari va qo'ng'iroq yozuvlari bu serverda saqlanmaydi
- **Sarkor trunk sinovi:** loyiha muallifining test akkaunti bilan; Sarkor Daniya IP'siga ruxsat bermasa — test uchun O'zbekistonda server olinadi (qaror #40)
- Ovoz kechikishi Daniya ↔ O'zbekiston ~80–120 ms — sinov uchun yetarli

### 9.2 Xaridor firmalar serverlari (qaror #20, #36)

- Har firma **o'z serverini** oladi; tavsiya — **O'zbekistondagi hosting** (masalan UzCloud): shaxsiy ma'lumotlar to'g'risidagi qonun (fuqarolarning telefon raqamlari, qo'ng'iroq yozuvlari, manzillari mamlakat hududida saqlanishi talab qilinadi) va SIP/ovoz uchun past kechikish
- Server talablari §9 jadvalida; hostingdan oldindan tasdiqlanadi: statik IPv4, UDP portlar (SIP/RTP/TURN) ochiq
- O'rnatish: toza Ubuntu → o'rnatish skripti (superadmin login/paroli bilan, qaror #58) → panelda sozlash (§0). Test ma'lumotlari ko'chirilmaydi

---

## 10. Qo'shimcha takliflar

1. **Takroriy mijozni tez xizmat qilish** — qo'ng'iroq kelganda oxirgi manzil avtomatik tanlanadi; operator faqat "Tasdiqlash" bosadi (3–5 soniyada buyurtma)
2. **IVR orqali avtomatik buyurtma (keyingi bosqich)** — doimiy mijoz qo'ng'iroq qilganda: "Oxirgi manzilingizga taksi chaqirish uchun 1 ni bosing" → operatorsiz buyurtma. Operator yukini sezilarli kamaytiradi
3. **Oldindan buyurtma (pre-order)** — "Ertaga soat 6:00 ga" — belgilangan vaqtdan 15 daqiqa oldin dispatcher ishga tushadi
4. **Haydovchi balansi va komissiya** — §5c ga qarang (qaror #23)
5. **Firibgarlikka qarshi** — haydovchi buyurtmani "yakunladi" lekin GPS harakatlanmagan; soxta GPS (mock location) aniqlash
6. **Operator KPI** — o'rtacha javob vaqti, buyurtma yaratish vaqti, javobsiz qo'ng'iroqlar
7. **Audit log** — kim qaysi buyurtmani bekor qildi, narxni o'zgartirdi (spatie/laravel-activitylog)
8. **Ko'p tillilik** — ✅ qaror #44 (o'zbek + rus); mo'ljal qidiruvi lotin/kirill/rus yozuvlarini bir xil topadi (transliteratsiya)
9. **Monitoring** — Laravel Pulse / Sentry, server uchun Uptime Kuma; Asterisk trunk uzilsa Telegram'ga ogohlantirish
10. **Zaxira nusxa** — PostgreSQL kunlik backup boshqa serverga + qo'ng'iroq yozuvlarini arxivlash

---

## 11. Bosqichlar (roadmap)

### Bosqich 0 — Tayyorgarlik (1 hafta)

- [x] Savollarga javoblar (§12)
- [x] O'rnatish skripti `deploy/install.sh` (toza Ubuntu; `--dev` — lokal VM), PostgreSQL + PostGIS'ga o'tish
- [x] spatie/permission, Reverb, Horizon, Sanctum, Magellan (PostGIS) o'rnatish
- [x] Rollar (superadmin, admin, operator, kassir, haydovchi), filial scope (`BelongsToBranch`), seederlar
- [x] Lokalizatsiya asosi: o'zbek + rus (backend, React `useTrans`, til almashtirish — qaror #44)
- [x] `regions` — OSM'dan import: `deploy/osm-prepare.sh` (Geofabrik PBF: O'zbekiston + Qirg'iziston + Tojikiston) → `php artisan regions:import`
- [x] CI: GitHub Actions PostgreSQL+PostGIS bilan; Pint, PHPStan, vp check
- [x] `CLAUDE.md`; lokal muhit — **Multipass virtual mashinasi** (Docker Compose o'rniga)
- [x] **Integratsiyalar** moduli (shifrlangan kalitlar, maxfiy maydonlar yashiriladi) va firma sozlamalari sahifasi. "Ulanishni tekshirish" har integratsiya ishlatiladigan bosqichda qo'shiladi
- [x] Ochiq ro'yxatdan o'tish, email tasdiqlash va o'z akkauntini o'chirish olib tashlandi (foydalanuvchilarni admin yaratadi)

### Bosqich 1 — MVP Backend + Operator paneli (3–4 hafta)

- [x] Ma'lumotlar modeli: filiallar, haydovchilar, mashinalar, hujjatlar, tarif klasslari va narxlar, mijozlar, mo'ljallar, buyurtmalar, balans, to'lovlar, kassa smenasi, chat
- [x] Narx hisoblash (`FareCalculator`) — barcha qoidalar testlar bilan
- [x] Efir dispetcheri: radius bosqichlari, atomik qabul qilish (parallel jarayonlar testi), operator tayinlashi, jarima/reyting/bloklash
- [x] Buyurtma hayot sikli, `order_events`, GPS treki bo'yicha tekshiruv
- [x] Haydovchi balansi: komissiya, minimal balans, to'ldirish, jarima
- [x] Haydovchi API (Sanctum): login, liniya, GPS, takliflar, bosqichlar, marshrut, balans, chat, push token
- [x] FCM yuborish (HTTP v1, firmaning service account'i), `device_tokens`
- [x] Xarita: `deploy/map-prepare.sh` (Protomaps → hudud `.pmtiles`, shrift va belgilar o'z serverda)
- [x] Operator paneli: faol buyurtmalar, MapLibre xarita, yangi buyurtma formasi, mijoz kartochkasi, mo'ljal qidiruvi va qo'shish, qo'lda tayinlash, bekor qilish
- [x] Reverb va Horizon (Supervisor), scheduler (cron) — jonli yangilanish sinovdan o'tdi
- [x] OSRM (Docker, `deploy/osrm-prepare.sh`) — yo'l bo'yicha masofa va ETA
- [x] Admin paneli: filiallar (hudud tanlash, filial sozlamalari), xodimlar, haydovchilar va mashinalar (tasdiqlash, hujjatlar, bloklash, balans), tarif klasslari va narxlar, mo'ljallar, mijozlar (qora ro'yxat), buyurtmalar tarixi, bosh sahifa ko'rsatkichlari
- [x] Kassir paneli: haydovchini topish, naqd to'ldirish, kassa smenasi
- [x] Operator panelida haydovchilar chati
- [x] OSM'dan POI'lar → `landmarks` (`php artisan landmarks:import`), qidiruv filialga yaqinlik bo'yicha
- [x] OSM'dan uy manzillari (ko'cha + uy raqami) va ko'chalar → `landmarks` (category `address`/`street`); qidiruv so'zlar bo'yicha ("Mustaqillik 12")
- [x] `simulate:drivers` — soxta haydovchilar OSRM marshrutlari bo'yicha yuradi, buyurtma oladi va yakunlaydi (`--orders=N` yuklama sinovi)

### Bosqich 2 — Haydovchi ilovasi Android ✅

- [x] Firma konfiguratsiyasi bo'yicha build (`android/firms/<firma>/firm.properties`, `-Pfirm=...`: applicationId, nom, server URL, rang, Firebase; keystore — qaror #35)
- [x] Ilova sozlamalari serverdan (`/me`: xarita uslubi, Reverb, xizmat hududi, tariflar) — ilovaga faqat server manzili yoziladi
- [x] Login, liniyaga chiqish/chiqish (tasdiq, bloklash, balans tekshiruvi), foreground service — fonda GPS (internet uzilsa yig'iladi)
- [x] Reverb (Pusher) — takliflar, buyurtma holati, chat; FCM — firma Firebase'i bilan (ixtiyoriy)
- [x] Taklif oynasi: signal, taymer, masofa, narx; operator tayinlagan taklif ogohlantirishi
- [x] Buyurtma bosqichlari, kutish taymeri, taksometr (GPS sakrashlari filtri, shahar ichi/tashqarisi), chek
- [x] Ilova ichida xarita (MapLibre, o'z serverdagi tile'lar): joylashuv, A/B nuqtalar, OSRM marshrut chizig'i
- [x] Chat (tezkor javoblar), balans tarixi, sozlamalar (til, batareya yo'riqnomasi)
- [x] Avto-yangilanish: admin panelda APK joylash (ABI bo'yicha), yuklab olish sahifasi va QR, majburiy yangilanish
- [x] Narx formulasi va taksometr unit testlari; emulyatorda to'liq oqim sinovi
- [x] "Dispetcherga qo'ng'iroq" (WebRTC) — Bosqich 3 da qo'shildi

### Bosqich 3 — Telefoniya + PBX panel ✅ (Sarkor ulanishidan tashqari)

- [x] Asterisk 20 o'rnatish skriptda (Ubuntu paketi), PJSIP + WSS (nginx `/ws`), ICE/STUN, DTLS sertifikat
- [ ] Sarkor Telecom trunk: test akkaunt bilan ulash va sinash (§5.2) — akkaunt olingach
- [x] Asterisk Realtime ↔ PostgreSQL (`ps_*` jadvallar, ODBC); trunk va ichki raqamlar panelda saqlanganda darhol ishlaydi
- [x] Qo'ng'iroq mantig'i ARI'da (Stasis `ibtaxi`, `php artisan ari:listen` supervisor ostida): navbat, eng uzoq bo'sh turgan operatorga taqsimlash, javob bermasa keyingisi (va tanaffusga), kutish limiti, salomlashuv + kutish musiqasi, IVR (raqam bir nechta filialda), mijoz tarixi bo'yicha yo'naltirish
- [x] Telefoniya bo'limi: SIP trunklar (holati), raqamlar ↔ filiallar + routing rejimlari (§5.2a), liniyalar limiti (trunk/filial/avto-qo'ng'iroq ulushi, §5.3), qo'ng'iroq markazi sozlamalari; ichki raqamlar operator/haydovchi bilan avtomatik
- [x] Operator softfoni brauzerda (JsSIP): holat (tayyor/tanaffus/oflayn), javob berish, mikrofon, qayta qo'ng'iroq, javobsizlar ro'yxati; kiruvchi qo'ng'iroq kartochkasi buyurtma formasini to'ldiradi va buyurtma qo'ng'iroqqa bog'lanadi
- [x] Haydovchi ilovasida "Dispetcherga qo'ng'iroq" (WebView + JsSIP), operator panelida "Haydovchi #pozivnoy" kartochkasi. Operator → haydovchi: haydovchining mobil raqamiga (trunk orqali)
- [x] Yozib olish (ko'prik → MP3, `storage/app/private/recordings`, faqat panel orqali), muddati o'tganlarni o'chirish (`recordings:cleanup`), CDR (`calls`), qora ro'yxat (rad etiladi)
- [x] Qo'ng'iroqlar bo'limi: jonli monitoring (5 s), tarix + filtrlar + yozuv pleyeri, statistika (javob berilgan/javobsiz, liniya bandligi sababli yo'qotilgan, o'rtacha kutish/suhbat, soatlar, operatorlar)
- [x] Avtomatik ovozli xabarlar: tayinlandi (ETA bilan, mijoz liniyadan tushgach), yetib keldi, hali kutmoqda, topilmadi, bekor qilindi; 2 marta takror, "1" — qayta eshitish; javobsiz bo'lsa qayta urinish, so'ng operatorga belgi
- [x] Ovozli bo'laklar kutubxonasi (SpeechKit, ayol ovozi, 8 kHz): panelda tinglash / matnni tuzatib qayta generatsiya / o'z yozuvini yuklash; filial salomlashuvi bir butun audio
- [x] `MessageComposer` (davlat raqami, son, rang, model → bo'laklar) + testlar; telefoniya testlari (ARI soxtalashtirilgan) va haqiqiy Asterisk + Chrome + Android emulyatorda uchma-uch sinov
- [x] Filial ish vaqti (tungi smena ham) va bayram kunlari: ishdan tashqari qo'ng'iroqqa xabar, "javobsiz" ro'yxatiga tushmaydi

### Bosqich 3b — Mahsulotlashtirish ✅ (Daniya serverida sinovdan tashqari)

- [x] O'rnatish skripti: `sudo deploy/install.sh --prod --domain=… --email=…` — toza Ubuntu 24.04 → to'liq tizim (PHP, PostgreSQL+PostGIS, Redis, Asterisk, OSRM, xarita, hududlar va mo'ljallar, Reverb/Horizon/ARI supervisor, Let's Encrypt, ufw, fail2ban, kunlik zaxira); idempotent. Toza Multipass VM'da sinaldi
- [ ] Daniya (webdock.io) test serverida haqiqiy domen bilan sinash
- [x] Birinchi kirish ustasi: firma nomi, til, superadmin, birinchi filial (shahar/tuman)
- [x] `php artisan ibtaxi:update` (zaxira → kod → bog'liqliklar → build → migratsiya → xizmatlarni qayta ishga tushirish), `--rollback`; `ibtaxi:backup` (kunlik 02:30, 14 kun); `VERSION` va `CHANGELOG.md`
- [x] Firma ilovasini build qilish yo'riqnomasi (`android/firms/README.md`, keystore saqlash bilan)

### Bosqich 4 — Pilot birinchi xaridor firma bilan (2 hafta, qaror #45)

- [x] Click SHOP API (prepare/complete) va Payme Merchant API (JSON-RPC, bekor qilishda qaytarish) — pozivnoy bo'yicha; ilovada "Balansni to'ldirish" (Click/Payme havolalari). Haqiqiy merchant kalitlari bilan sinov — firma shartnomasidan keyin
- [ ] Firma serverini o'rnatish (o'rnatish skripti + `ibtaxi:init`, `deploy/deploy.md`)
- [ ] Firma SIP trunki va raqam(lar)ini ulash va sinash (§5.2)
- [x] Yuklama sinovi (lokal VM, 2 CPU / 4 GB): 100 soxta haydovchi + ~210 buyurtma/soat — hammasi tayinlandi, xatosiz, server yuklamasi ~0.4. Firma serverida (haqiqiy telefonlar bilan) takrorlanadi
- [ ] Firmaning yuklamasiga qarab kanallar sonini tekshirish (1000+/kun → 8–10 kanal)
- [ ] Firma filial(lar)ida (panelda tanlangan shahar/tuman — qaror #31) real sinov
- [x] Mo'ljallar bazasi OSM'dan avtomatik to'ldiriladi (o'rnatish skriptida; Farg'ona vodiysi uchun ~44 ming) — pilotda operatorlar to'ldirib boradi
- [ ] Xatolarni tuzatish, operator fikr-mulohazasi

### Bosqich 5 — Kengaytmalar

- [x] Kengaytirilgan hisobotlar: kunlik buyurtma/tushum/komissiya, bekor qilish sabablari, haydovchilar, operatorlar (o'rtacha javob vaqti), to'lov manbalari, buyurtma manbalari, kanal bandligi va tavsiya; CSV
- [x] IVR avto-buyurtma (sozlamada yoqiladi) va oldindan buyurtma (15 daqiqa oldin efirga)
- [x] Ilova ichida ovozli navigatsiya (qaror #49)
- [x] §10 takliflari: soxta GPS aniqlash, audit jurnali, monitoring (panel + Telegram), zaxirani boshqa serverga (SFTP)

**MVP taxminiy muddati:** **16–20 hafta** (1 dasturchi + AI, bosqichlar ketma-ket). Dastlabki 12–15 haftalik baho reja kengaygani uchun oshirildi: PBX panel, mahsulotlashtirish (o'rnatish, yangilash, white-label ilova), Click/Payme, jarima/reyting, qo'shimcha narx qoidalari. Bosqich 1 tugagach qayta baholanadi.

### Ish uslubi (1 dasturchi + AI)

- **Bitta repozitoriy (monorepo):** Laravel ildizda, Android ilova `android/` papkasida. AI backend API va ilovani bir vaqtda ko'radi, API kontrakti mos kelmasligi xavfi kamayadi
- **`CLAUDE.md`** — loyiha qoidalari, buyruqlar, arxitektura qisqacha (AI har sessiyada o'qiydi); `docs/` — rejalar va qarorlar
- **Kichik, tugallangan qadamlar:** har bir vazifa = bitta branch → testlar → ko'rib chiqish → merge
- **Testlar majburiy** (Pest/PHPUnit) — ayniqsa dispatcher, narx hisoblash, buyurtma statuslari: AI yozgan kodni testlar himoya qiladi
- **API kontrakti** (OpenAPI yoki Laravel API Resources + hujjat) — Android ilova shunga qarab yoziladi
- **Lokal muhit:** Multipass virtual mashinasi (Ubuntu 24.04) — xaridor serveri bilan bir xil o'rnatish skripti bilan ko'tariladi (PostgreSQL+PostGIS, Redis, keyin Asterisk, OSRM)
- **Bosqich 3 dan oldin** Android'ni emulator/real telefonda soxta haydovchilar bilan sinash uchun `php artisan simulate:drivers` buyrug'i (N ta haydovchini shahar bo'ylab harakatlantiradi) — bitta odam butun tizimni sinay olishi uchun

---

## 12. Ochiq savollar ❓

✅ Arxitekturaga ta'sir qiladigan barcha savollar yopildi (2026-10-11). Javoblar quyida — tarix uchun saqlanadi.

**Amalga oshirish paytida tekshiriladigan narsalar (savol emas, nazorat ro'yxati):**

- [ ] Sarkor qo'shimcha kanal narxi 60 000 so'm — oylikmi yoki bir martalik
- [ ] Sarkor: avto-qo'ng'iroqlarda qisqa raqam Caller ID sifatida ko'rinadimi
- [ ] Har firma hostingi: statik IPv4 va SIP/RTP UDP portlari (5060, 10000–20000, 3478) ochiq
- [ ] Yandex SpeechKit: o'zbekcha bo'laklar talaffuzini eshitib tekshirish

### Biznes

1. ~~Qaysi shaharlar? Pilot qayerda?~~ ✅ Farg'ona vodiysi (qaror #30); shahar/tuman admin panelda tanlanadi (qaror #31)
2. ~~Nechta filial, raqamlar qanday?~~ ✅ Filiallar soni cheklanmaydi (superadmin xohlagancha qo'shadi). Raqamlar universal: ma'lum filialga ma'lum raqam, yoki bitta raqam tanlangan bir nechta filialga — panelda sozlanadi (qaror #15, §5.2a)
3. ~~Taxminiy hajm?~~ ✅ Kuniga 1000+ buyurtma (qaror #32, hisob §9.3)
4. ~~Narx hisoblash?~~ ✅ Boshlang'ich narx (ichida N km) + har km narxi (qaror #21); kutish / tungi / shahar tashqarisi — panelda yoqiladi (qaror #33); yaxlitlash 500 so'm (qaror #34)
5. ~~To'lov?~~ ✅ Faqat naqd (qaror #22)
6. ~~Daromad modeli?~~ ✅ Buyurtmadan foiz, oldindan to'ldirilgan balansdan (qaror #23); komissiya 10%, minimal balans 5 000 so'm — filial bo'yicha panelda (qaror #34); kassa — kassir roli (qaror #25)
7. ~~Haydovchilar kim?~~ ✅ O'z mashinasi bilan ishlaydigan hamkorlar (qaror #26)
8. ~~Tarif klasslari?~~ ✅ Standart / Komfort / Yuk / Dostavka, panelda sozlanadi (qaror #27)
9. ~~Buyurtma qabul qilish usuli?~~ ✅ Efir — barcha yaqin haydovchilarga, kim birinchi olsa (qaror #28, §6)
10. ~~Rad etish uchun jarima?~~ ✅ Ha — jarima + reyting pasayishi + vaqtincha bloklash, panelda sozlanadi (qaror #29, §6)

### Telefoniya

11. ~~SIP trunk provayderi?~~ ✅ Sarkor Telecom (qaror #12):
    - ~~Shartnoma kimning nomida?~~ ✅ Har firma o'zi tuzadi (qaror #40)
    - ~~Nechta raqam, har filialga alohidami?~~ ✅ Hozir 1 ta raqam; bog'lanish universal, panelda sozlanadi (qaror #15)
    - ~~Test akkaunt?~~ ✅ Loyiha muallifi o'zi oladi (qaror #40)
    - ~~Qisqa raqam?~~ ✅ Rejada bor (qaror #41)
    - ~~Kanal narxi?~~ ✅ 60 000 so'm (qo'shimcha kanal)
12. ~~Parallel liniyalar?~~ ✅ Admin panelda sozlanadi (qaror #13); hozir Sarkor'da **4 ta kanal**
13. ~~Asterisk / panel.1call.uz?~~ ✅ Asterisk noldan o'rnatiladi, PBX panel ibTaxi ichida (qaror #10)
14. ~~Yozib olish? Muddat?~~ ✅ Ha (qaror #11); 90 kun, panelda o'zgartiriladi (qaror #34)
15. ~~Ovozli xabarlar: diktor yoki TTS? Ovoz?~~ ✅ TTS, oldindan generatsiya, faqat ayol ovozi (qaror #4, #14). TTS xizmati: Yandex SpeechKit (qaror #17). ~~Xizmat nomi?~~ ✅ Admin panelda kiritiladi (qaror #19)
    15b. ~~Erkak ovoz?~~ ✅ Ishlatilmaydi — faqat ayol ovozi (qaror #14)
    15a. ~~Qachon qo'ng'iroq qilinadi?~~ ✅ Ikkalasida ham: tayinlanganda va yetib kelganda (qaror #16)

### Texnik

16. ~~Nechta VPS?~~ ✅ 1 ta (qaror #9). ~~Test serveri?~~ ✅ Webdock.io, Daniya (qaror #18). ~~Production hosting?~~ ✅ UzCloud (qaror #20)
    16a. ~~Daniya IP'siga ruxsat?~~ ✅ Ruxsat beradi, bo'lmasa test uchun O'zbekistonda server olinadi (qaror #40)
17. ~~Xarita qaysi?~~ ✅ Javob: bepul OSM yondashuvi (qaror #2)
18. ~~SMS provayderi?~~ ✅ SMS ishlatilmaydi (qaror #4)
19. ~~Minimal Android versiyasi?~~ ✅ Android 10+ (qaror #5)
20. ~~iOS kerakmi?~~ ✅ Yo'q, umuman (qaror #43)
21. ~~Mijoz uchun ilova?~~ ✅ Hech qachon kerak emas (qaror #7)
22. ~~Jamoa?~~ ✅ 1 dasturchi + AI (qaror #8)
23. ~~Ilova ichidagi xarita darajasi?~~ ✅ Javob: (a) xarita + marshrut + masofa + ETA (qaror #3)
24. ~~Masked call?~~ ✅ Kerak emas (qaror #42)
25. ~~Tillar?~~ ✅ O'zbek + rus (qaror #44)
26. ~~Pilot firma?~~ ✅ Birinchi xaridor firma; 1call faqat andoza (qaror #45)
27. ~~FastPanel?~~ ✅ To'liq voz kechildi, toza Ubuntu (qaror #46)
28. ~~Haydovchi ↔ dispetcher aloqasi?~~ ✅ Qo'ng'iroq (WebRTC) + chat (qaror #47)
