import { useSyncExternalStore } from 'react';
import { serverNow } from '@/lib/server-clock';

// Butun sahifa uchun bitta 1 s taymer: har qator o'z setInterval'ini ochsa yuzlab taymer bo'lardi
const listeners = new Set<() => void>();
let timer: ReturnType<typeof setInterval> | null = null;
let current = serverNow();

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    if (timer === null) {
        current = serverNow();
        timer = setInterval(() => {
            current = serverNow();
            listeners.forEach((l) => l());
        }, 1000);
    }

    return () => {
        listeners.delete(listener);
        if (listeners.size === 0 && timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    };
}

/**
 * Server soati bo'yicha "hozir" (ms), `stepMs` qadamiga yaxlitlangan — komponent faqat qadam
 * almashganda qayta chiziladi (masalan 5000 — har 5 soniyada).
 */
export function useServerNow(stepMs = 1000): number {
    return useSyncExternalStore(
        subscribe,
        () => Math.floor(current / stepMs) * stepMs,
        () => Math.floor(serverNow() / stepMs) * stepMs,
    );
}
