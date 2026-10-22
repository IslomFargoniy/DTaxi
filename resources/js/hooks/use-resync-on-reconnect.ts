import { useConnectionStatus } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/**
 * Reverb uzilib qayta ulanganda chaqiriladi: uzilish paytidagi hodisalar kelmaydi,
 * shuning uchun ro'yxatlar serverdan qayta olinadi. Birinchi ulanishda chaqirilmaydi.
 */
export function useResyncOnReconnect(resync: () => void): void {
    const status = useConnectionStatus();
    // Pusher boshlang'ich holati ham "disconnected" — faqat bir marta ulangandan keyingi uzilish sanaladi
    const everConnected = useRef(false);
    const wasDisconnected = useRef(false);
    const callback = useRef(resync);

    useEffect(() => {
        callback.current = resync;
    }, [resync]);

    useEffect(() => {
        if (status === 'connected') {
            everConnected.current = true;
            if (wasDisconnected.current) {
                wasDisconnected.current = false;
                callback.current();
            }
        } else if (everConnected.current) {
            wasDisconnected.current = true;
        }
    }, [status]);
}
