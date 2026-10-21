import { useEffect, useState } from 'react';

/**
 * Haydovchi GPS hodisalari qachon oxirgi marta kelgani (operator sahifasi belgilaydi) —
 * sarlavhadagi ulanish belgisi shundan "GPS oqimi to'xtadimi"ni ko'rsatadi.
 */
type GpsHealth = { lastAt: number | null; listeners: Set<() => void> };

type GlobalWithGps = typeof globalThis & { __DTAXI_GPS__?: GpsHealth };

const g = globalThis as GlobalWithGps;
const health: GpsHealth = g.__DTAXI_GPS__ ?? {
    lastAt: null,
    listeners: new Set(),
};
g.__DTAXI_GPS__ = health;

let lastNotify = 0;

export function markGpsEvent(): void {
    health.lastAt = Date.now();
    // Hodisalar ko'p — sarlavha soniyasiga bir martadan ko'p qayta chizilmasin
    if (health.lastAt - lastNotify > 1000) {
        lastNotify = health.lastAt;
        health.listeners.forEach((cb) => cb());
    }
}

/** Oxirgi GPS hodisasidan beri o'tgan soniya (null — hali kelmagan); har 5 soniyada yangilanadi */
export function useGpsAge(): number | null {
    const [state, setState] = useState(() => ({
        now: Date.now(),
        lastAt: health.lastAt,
    }));

    useEffect(() => {
        const tick = () => setState({ now: Date.now(), lastAt: health.lastAt });
        health.listeners.add(tick);
        const id = setInterval(tick, 5000);

        return () => {
            health.listeners.delete(tick);
            clearInterval(id);
        };
    }, []);

    return state.lastAt === null
        ? null
        : Math.max(0, Math.round((state.now - state.lastAt) / 1000));
}
