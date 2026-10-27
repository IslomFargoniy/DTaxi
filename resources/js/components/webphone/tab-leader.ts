import { globalSip } from './sip-state';
import type { TabRole } from './sip-state';

/**
 * Bir nechta varaqda panel ochilsa — ichki raqamni faqat bittasi ro'yxatdan o'tkazadi
 * (aks holda qo'ng'iroq tasodifiy varaqqa tushadi). BroadcastChannel orqali "lider" saylanadi:
 * lider har 2 soniyada signal beradi, signal 5 soniya kelmasa — boshqa varaq o'rnini egallaydi.
 */

type Message =
    | { type: 'hello'; id: string }
    | { type: 'heartbeat'; id: string }
    | { type: 'resign'; id: string }
    | { type: 'takeover'; id: string }
    // Lider suhbatda — liderlik berilmaydi (aks holda faol qo'ng'iroq uzilardi)
    | { type: 'refuse'; id: string; to: string };

const CHANNEL = 'dtaxi-sip-leader';
const HEARTBEAT_MS = 2000;
const LEADER_TIMEOUT_MS = 5000;
const CLAIM_WAIT_MS = 600;

type LeaderState = {
    started: boolean;
    id: string;
    channel: BroadcastChannel | null;
    lastLeaderSeen: number;
    heartbeat: ReturnType<typeof setInterval> | null;
    watchdog: ReturnType<typeof setInterval> | null;
    /** "Shu yerda ishlatish" so'rovi javobini kutish (rad etilsa — chaqiriladi) */
    takeover: {
        timer: ReturnType<typeof setTimeout>;
        onRefused?: () => void;
    } | null;
};

type GlobalWithLeader = typeof globalThis & {
    __DTAXI_SIP_LEADER__?: LeaderState;
};

const g = globalThis as GlobalWithLeader;
const state: LeaderState = g.__DTAXI_SIP_LEADER__ ?? {
    started: false,
    id: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`,
    channel: null,
    lastLeaderSeen: 0,
    heartbeat: null,
    watchdog: null,
    takeover: null,
};
g.__DTAXI_SIP_LEADER__ = state;

function setRole(role: TabRole) {
    if (globalSip.tabRole === role) {
        return;
    }
    globalSip.tabRole = role;
    if (role === 'follower' && globalSip.ua) {
        // Boshqa varaq telefonni oldi — bu yerdagi ulanish yopiladi
        globalSip.destroyUA();
    }
    globalSip.notify();
}

function post(message: Message) {
    try {
        state.channel?.postMessage(message);
    } catch {
        // Kanal yopilgan
    }
}

function becomeLeader() {
    setRole('leader');
    post({ type: 'heartbeat', id: state.id });
    if (!state.heartbeat) {
        state.heartbeat = setInterval(
            () => post({ type: 'heartbeat', id: state.id }),
            HEARTBEAT_MS,
        );
    }
}

function becomeFollower() {
    if (state.heartbeat) {
        clearInterval(state.heartbeat);
        state.heartbeat = null;
    }
    setRole('follower');
}

/** Lider yo'q bo'lsa — qisqa kutib, hech kim javob bermasa lider bo'lamiz */
function claim() {
    globalSip.tabRole = 'pending';
    globalSip.notify();
    const startedAt = Date.now();
    post({ type: 'hello', id: state.id });
    setTimeout(() => {
        if (state.lastLeaderSeen > startedAt) {
            becomeFollower();
        } else {
            becomeLeader();
        }
    }, CLAIM_WAIT_MS);
}

function onMessage(event: MessageEvent<Message>) {
    const message = event.data;
    if (!message || message.id === state.id) {
        return;
    }
    switch (message.type) {
        case 'hello':
            // Yangi varaq so'rayapti — lider bo'lsak, darhol javob beramiz
            if (globalSip.tabRole === 'leader') {
                post({ type: 'heartbeat', id: state.id });
            }
            break;
        case 'heartbeat':
            if (globalSip.tabRole === 'leader') {
                // Ikki lider bir vaqtda paydo bo'ldi — kichik id qoladi.
                // Suhbat paytida o'z ulanishimizni uzmaymiz
                if (message.id < state.id && globalSip.callState === 'idle') {
                    state.lastLeaderSeen = Date.now();
                    becomeFollower();
                }

                return;
            }
            state.lastLeaderSeen = Date.now();
            if (globalSip.tabRole === 'pending') {
                becomeFollower();
            }
            break;
        case 'resign':
            if (globalSip.tabRole === 'follower') {
                state.lastLeaderSeen = 0;
                // Bir vaqtda bir nechta varaq da'vo qilmasin
                setTimeout(claim, Math.random() * 300);
            }
            break;
        case 'takeover':
            if (globalSip.tabRole === 'leader') {
                if (globalSip.callState !== 'idle') {
                    // Suhbat (yoki jiringlash) shu varaqda — telefon bu yerda qoladi
                    post({ type: 'refuse', id: state.id, to: message.id });

                    return;
                }
                state.lastLeaderSeen = Date.now();
                becomeFollower();
            }
            break;
        case 'refuse':
            if (message.to === state.id && state.takeover) {
                clearTimeout(state.takeover.timer);
                const { onRefused } = state.takeover;
                state.takeover = null;
                state.lastLeaderSeen = Date.now();
                onRefused?.();
            }
            break;
    }
}

/** Varaq yopilganda/chiqishda — liderlikdan voz kechish (boshqa varaq darhol oladi) */
export function resignLeadership() {
    if (globalSip.tabRole === 'leader') {
        post({ type: 'resign', id: state.id });
    }
}

/**
 * "Telefonni shu varaqda ishlatish" — liderlikni olish. Lider suhbatda bo'lsa rad etadi (onRefused);
 * javob kutilmasdan lider bo'linsa, o'sha varaqdagi faol qo'ng'iroq uzilardi.
 */
export function takeLeadership(onRefused?: () => void) {
    if (!state.channel) {
        becomeLeader();

        return;
    }
    if (state.takeover) {
        clearTimeout(state.takeover.timer);
    }
    post({ type: 'takeover', id: state.id });
    state.takeover = {
        onRefused,
        timer: setTimeout(() => {
            state.takeover = null;
            becomeLeader();
        }, CLAIM_WAIT_MS),
    };
}

export function startLeaderElection() {
    if (state.started) {
        return;
    }
    state.started = true;
    if (typeof BroadcastChannel === 'undefined') {
        // Eski brauzer — har varaq o'zi ulanadi
        setRole('leader');

        return;
    }
    state.channel = new BroadcastChannel(CHANNEL);
    state.channel.onmessage = onMessage;
    window.addEventListener('pagehide', resignLeadership);
    state.watchdog = setInterval(() => {
        if (
            globalSip.tabRole === 'follower' &&
            Date.now() - state.lastLeaderSeen > LEADER_TIMEOUT_MS
        ) {
            claim();
        }
    }, 1000);
    claim();
}

export function stopLeaderElection() {
    resignLeadership();
    if (state.heartbeat) {
        clearInterval(state.heartbeat);
    }
    if (state.watchdog) {
        clearInterval(state.watchdog);
    }
    state.heartbeat = null;
    state.watchdog = null;
    state.channel?.close();
    state.channel = null;
    state.started = false;
    globalSip.tabRole = 'pending';
}
