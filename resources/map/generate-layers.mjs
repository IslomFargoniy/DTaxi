// Xarita qatlamlari (Protomaps basemaps) — JSON'ga yoziladi; server ularni to'liq uslubga yig'adi
// (/map/style.json), panel ham, Android ilova ham bir xil uslubdan foydalanadi.
// Ishga tushirish: node resources/map/generate-layers.mjs
import { layers, namedFlavor } from '@protomaps/basemaps';
import { writeFileSync } from 'node:fs';

for (const flavor of ['light', 'dark']) {
    for (const lang of ['uz', 'ru']) {
        const out = new URL(`./layers-${flavor}-${lang}.json`, import.meta.url);
        writeFileSync(
            out,
            JSON.stringify(layers('protomaps', namedFlavor(flavor), { lang })),
        );
    }
}
