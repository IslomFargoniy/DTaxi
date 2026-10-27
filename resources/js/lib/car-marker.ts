/**
 * Xaritadagi haydovchi belgisi — yuqoridan ko'rinishdagi mashina (tumshug'i shimolga). Mijoz ilovasidagi bilan
 * bir xil shakl (android/client CarMarker.kt); operator xaritasida kuzov rangi haydovchi holatini bildiradi.
 */
import type { Map as MapLibreMap } from 'maplibre-gl';

/** Kuzov rangi: holat → rang (driversGeoJson dagi status qiymatlari) */
export const CAR_COLORS: Record<string, string> = {
    free: '#16a34a',
    busy: '#dc2626',
    stale: '#6b7280',
};

// 34x62 birlik: mashina 23x54, atrofida soya uchun joy
const W = 34;
const H = 62;

function carSvg(body: string): string {
    const outline = '#111111';
    const glass = '#1f2329';
    const rect = (
        x: number,
        y: number,
        w: number,
        h: number,
        r: number,
        fill: string,
    ) =>
        `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="${fill}"/>`;

    return (
        `<svg xmlns="http://www.w3.org/2000/svg" width="${W * 2}" height="${H * 2}" viewBox="${-W / 2} ${-H / 2} ${W} ${H}">` +
        '<defs><filter id="s" x="-40%" y="-30%" width="180%" height="160%"><feDropShadow dx="0" dy="1" stdDeviation="1.2" flood-color="#000" flood-opacity=".35"/></filter></defs>' +
        '<g filter="url(#s)">' +
        rect(-13.5, -17, 3, 8, 1.2, outline) +
        rect(10.5, -17, 3, 8, 1.2, outline) +
        rect(-13.5, 12, 3, 8, 1.2, outline) +
        rect(10.5, 12, 3, 8, 1.2, outline) +
        `<rect x="-11.5" y="-27" width="23" height="54" rx="8.5" fill="${body}" stroke="${outline}" stroke-width="1.8"/>` +
        '</g>' +
        `<path d="M-8.5 -12 Q0 -16.5 8.5 -12 L7.2 -5.5 Q0 -7.5 -7.2 -5.5 Z" fill="${glass}"/>` +
        `<rect x="-7.4" y="-4.5" width="14.8" height="16" rx="2.5" fill="${body}" stroke="${glass}" stroke-opacity=".35" stroke-width="1"/>` +
        `<path d="M-7.2 14 Q0 15.8 7.2 14 L8 19 Q0 21 -8 19 Z" fill="${glass}"/>` +
        rect(-9, -26, 4, 2.2, 1, '#fff6c8') +
        rect(5, -26, 4, 2.2, 1, '#fff6c8') +
        rect(-9, 23.8, 4, 2, 1, '#d23b2a') +
        rect(5, 23.8, 4, 2, 1, '#d23b2a') +
        rect(-14.5, -8.5, 3, 2, 1, outline) +
        rect(11.5, -8.5, 3, 2, 1, outline) +
        '</svg>'
    );
}

/**
 * Har holat uchun "car-<status>" rasmini xaritaga qo'shadi (2x — retina'da ham aniq).
 * Rasmlar yuklangach resolve bo'ladi; qatlam shundan keyin qo'shiladi.
 */
export function addCarImages(map: MapLibreMap): Promise<void> {
    return Promise.all(
        Object.entries(CAR_COLORS).map(
            ([status, color]) =>
                new Promise<void>((resolve) => {
                    const image = new Image(W * 2, H * 2);
                    image.onload = () => {
                        if (!map.hasImage(`car-${status}`)) {
                            map.addImage(`car-${status}`, image, {
                                pixelRatio: 2,
                            });
                        }
                        resolve();
                    };
                    // Rasm yuklanmasa ham xarita ishlashda davom etadi (qatlam belgisiz qoladi)
                    image.onerror = () => resolve();
                    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(carSvg(color))}`;
                }),
        ),
    ).then(() => undefined);
}

/** Ikki nuqta orasidagi yo'nalish (shimoldan soat yo'nalishida, gradus) */
export function bearing(
    from: { lat: number; lng: number },
    to: { lat: number; lng: number },
): number {
    const rad = Math.PI / 180;
    const dLng = (to.lng - from.lng) * rad;
    const y = Math.sin(dLng) * Math.cos(to.lat * rad);
    const x =
        Math.cos(from.lat * rad) * Math.sin(to.lat * rad) -
        Math.sin(from.lat * rad) * Math.cos(to.lat * rad) * Math.cos(dLng);

    return (Math.atan2(y, x) / rad + 360) % 360;
}

/** Taxminiy masofa (metr) — kichik siljishlarda yo'nalish hisoblanmasligi uchun */
export function distanceMeters(
    a: { lat: number; lng: number },
    b: { lat: number; lng: number },
): number {
    const dLat = (b.lat - a.lat) * 111_320;
    const dLng = (b.lng - a.lng) * 111_320 * Math.cos((a.lat * Math.PI) / 180);

    return Math.hypot(dLat, dLng);
}
