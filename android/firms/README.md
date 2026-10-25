# Firmalar konfiguratsiyasi

Har bir xaridor firma uchun alohida papka (qaror #35, reja §0.1):

```
firms/<firma>/
  firm.properties      applicationId, ilova nomi, server manzili, rang, Firebase sozlamalari
  logo.png             (ixtiyoriy) ilova belgisi, 512×512
  keystore.jks         imzo kaliti — HECH QACHON git'ga qo'shilmaydi, yo'qolsa yangilanish o'rnatilmaydi
  keystore.properties  storePassword, keyAlias, keyPassword
```

Build:

```bash
cd android
./gradlew :driver:assembleRelease -Pfirm=ibrattaxi
# natija: public/android/dtaxi.<versiya>.apk + version.json → deploy/upload-apk.sh bilan firma serveriga
```

Firma papkasida `keystore.jks` va `keystore.properties` bo'lmasa `assembleRelease` xato bilan to'xtaydi —
debug kalit bilan chiqqan ilova keyin firma kaliti bilan yangilanmaydi. Debug kalit faqat `dev` va `lan` uchun.

Yangi keystore:

```bash
keytool -genkeypair -v -keystore firms/<firma>/keystore.jks -alias driver -keyalg RSA -keysize 2048 -validity 36500
```

## Lokal sinov serveri (self-signed HTTPS)

"Dispetcherga qo'ng'iroq" (WebRTC) sahifasi https orqali ochiladi. Lokal VM'ning self-signed sertifikatiga
faqat **debug** build ishonishi uchun uni firma papkasiga qo'ying (git'ga tushmaydi):

```bash
multipass exec dtaxi -- cat /etc/ssl/dtaxi-dev.crt > firms/dev/dev-ca.pem
```

Firma serverida haqiqiy (Let's Encrypt) sertifikat bo'ladi — bu fayl kerak emas.
