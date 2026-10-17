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
./gradlew assembleRelease -Pfirm=ibrattaxi
# natija: app/build/outputs/apk/release/app-release.apk → firma serveriga driver-app/ papkasiga
```

Yangi keystore:

```bash
keytool -genkeypair -v -keystore firms/<firma>/keystore.jks -alias driver -keyalg RSA -keysize 2048 -validity 36500
```
