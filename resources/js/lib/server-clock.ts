// Brauzer soati serverdan farq qilsa (operator kompyuteri soati noto'g'ri) — server vaqti bo'yicha hisoblash
let offsetMs = 0;

/** Sahifa ochilganda serverdan kelgan vaqt (ms) bilan farqni eslab qolish */
export function syncServerClock(serverTimeMs: number | undefined): void {
    if (typeof serverTimeMs === 'number' && Number.isFinite(serverTimeMs)) {
        offsetMs = serverTimeMs - Date.now();
    }
}

/** Hozirgi vaqt server soati bo'yicha (ms) */
export function serverNow(): number {
    return Date.now() + offsetMs;
}
