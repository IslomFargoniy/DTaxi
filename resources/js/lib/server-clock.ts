// Brauzer soati serverdan farq qilsa (operator kompyuteri soati noto'g'ri) — server vaqti bo'yicha hisoblash
let offsetMs = 0;
// Oxirgi qabul qilingan server vaqti: komponent har chizishda chaqiradi — o'sha qiymat bilan qayta hisoblansa
// farq kamayib boraverar va serverNow() sahifa ochilgan paytda "qotib" qolardi
let lastServerTimeMs: number | undefined;

/** Sahifa ochilganda (yoki yangi server vaqti kelganda) farqni eslab qolish — bir qiymat bir marta */
export function syncServerClock(serverTimeMs: number | undefined): void {
    if (
        typeof serverTimeMs === 'number' &&
        Number.isFinite(serverTimeMs) &&
        serverTimeMs !== lastServerTimeMs
    ) {
        lastServerTimeMs = serverTimeMs;
        offsetMs = serverTimeMs - Date.now();
    }
}

/** Hozirgi vaqt server soati bo'yicha (ms) */
export function serverNow(): number {
    return Date.now() + offsetMs;
}
