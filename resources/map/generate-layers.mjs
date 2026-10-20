// Xarita qatlamlari (Protomaps basemaps) — JSON'ga yoziladi; server ularni to'liq uslubga yig'adi
// (/map/style.json), panel ham, Android ilova ham bir xil uslubdan foydalanadi.
// Ishga tushirish: node resources/map/generate-layers.mjs
import { layers, namedFlavor } from '@protomaps/basemaps';
import { writeFileSync } from 'node:fs';

for (const flavor of ['light', 'dark']) {
    for (const lang of ['uz', 'ru', 'en']) {
        const out = new URL(`./layers-${flavor}-${lang}.json`, import.meta.url);
        writeFileSync(
            out,
            JSON.stringify(
                layers('protomaps', namedFlavor(flavor), { lang }).map(
                    (layer) =>
                        // Uy raqamlari 18 o'rniga 17-zoom'dan — haydovchi va operator manzilni tezroq topadi.
                        // Android'dagi MapLibre Native uy raqamlarini kursiv shriftda chizmaydi — oddiy shrift
                        layer.id === 'address_label'
                            ? {
                                  ...layer,
                                  minzoom: 17,
                                  layout: {
                                      ...layer.layout,
                                      'text-font': ['Noto Sans Regular'],
                                  },
                              }
                            : layer,
                ),
            ),
        );
    }
}
