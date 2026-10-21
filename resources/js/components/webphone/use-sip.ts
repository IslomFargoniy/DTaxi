import { useEffect, useState } from 'react';
import { globalSip } from './sip-state';
import type { CallState, SipStatus, TabRole } from './sip-state';

type SipView = { status: SipStatus; callState: CallState; tabRole: TabRole };

function view(): SipView {
    return {
        status: globalSip.status,
        callState: globalSip.callState,
        tabRole: globalSip.tabRole,
    };
}

/** Webfon holati (sahifalardagi tugmalar va sarlavha belgisi uchun) */
export function useSip(): SipView {
    const [state, setState] = useState(view);

    useEffect(() => {
        const sync = () =>
            setState((prev) => {
                const next = view();

                return prev.status === next.status &&
                    prev.callState === next.callState &&
                    prev.tabRole === next.tabRole
                    ? prev
                    : next;
            });
        globalSip.listeners.add(sync);
        sync();

        return () => {
            globalSip.listeners.delete(sync);
        };
    }, []);

    return state;
}

/** Bir bosishda qo'ng'iroq: telefon raqami yoki haydovchi pozivnoyi (server o'zi aniqlaydi) */
export function dial(target: string | null | undefined): void {
    const clean = (target ?? '').replace(/[^0-9A-Za-z*#]/g, '');
    if (clean.length < 2) {
        return;
    }
    globalSip.getAudioContext();
    globalSip.makeCall(clean);
}
