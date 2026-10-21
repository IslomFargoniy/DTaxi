/**
 * Haydovchi → dispetcher qo'ng'irog'i (qaror #47): ilova WebView'ida ochiladi.
 * Token va yopish Android JS ko'prigi (window.IbTaxi) orqali; SIP ma'lumotlari API'dan.
 */
import { UA, WebSocketInterface } from 'jssip';
import type { RTCSession } from 'jssip/lib/RTCSession';

type Bridge = {
    token(): string;
    locale(): string;
    close(): void;
    speaker?(on: boolean): void;
};
type Phone = {
    sip: { uri: string; password: string; ws: string };
    target: string;
};

declare global {
    interface Window {
        IbTaxi?: Bridge;
    }
}

const TEXT = {
    uz: {
        title: 'Dispetcher',
        connecting: 'Ulanmoqda…',
        ringing: 'Kutilmoqda…',
        waiting: 'Dispetcher javobini kutmoqda…',
        talking: 'Gaplashmoqda',
        ended: 'Qo’ng’iroq tugadi',
        failed: 'Ulanib bo’lmadi',
    },
    oz: {
        title: 'Диспетчер',
        connecting: 'Уланмоқда…',
        ringing: 'Кутилмоқда…',
        waiting: 'Диспетчер жавобини кутмоқда…',
        talking: 'Гаплашмоқда',
        ended: 'Қўнғироқ тугади',
        failed: 'Уланиб бўлмади',
    },
    ru: {
        title: 'Диспетчер',
        connecting: 'Подключение…',
        ringing: 'Ожидание…',
        waiting: 'Ожидание ответа диспетчера…',
        talking: 'Разговор',
        ended: 'Звонок завершён',
        failed: 'Не удалось подключиться',
    },
    en: {
        title: 'Dispatcher',
        connecting: 'Connecting…',
        ringing: 'Waiting…',
        waiting: 'Waiting for the dispatcher…',
        talking: 'Talking',
        ended: 'Call ended',
        failed: 'Could not connect',
    },
};

const bridge = window.IbTaxi;
const locale = bridge?.locale() ?? 'uz';
const t = TEXT[(locale in TEXT ? locale : 'uz') as keyof typeof TEXT];
const $ = (id: string) => document.getElementById(id) as HTMLElement;
const status = (text: string) => ($('status').textContent = text);
let session: RTCSession | null = null;
let ua: UA | null = null;
let timer = 0;
let poll = 0;

const authHeaders = () => ({
    Accept: 'application/json',
    Authorization: `Bearer ${bridge?.token() ?? ''}`,
});

/** Vaqt operator javob bergandan boshlanadi (navbat musiqasi paytida emas) */
function startTimer(): void {
    if (timer) {
        return;
    }
    status(t.talking);
    const started = Date.now();
    timer = window.setInterval(() => {
        const s = Math.floor((Date.now() - started) / 1000);
        $('timer').textContent =
            `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }, 1000);
}

function waitForOperator(): void {
    status(t.waiting);
    poll = window.setInterval(() => {
        fetch('/api/driver/v1/phone/call', { headers: authHeaders() })
            .then((r) => (r.ok ? r.json() : null))
            .then((call: { status: string | null } | null) => {
                if (
                    call?.status === 'answered' ||
                    call?.status === 'completed'
                ) {
                    window.clearInterval(poll);
                    startTimer();
                }
            })
            .catch(() => undefined);
    }, 1000);
}

const stopAll = () => {
    window.clearInterval(timer);
    window.clearInterval(poll);
};

const close = (delay = 1200) =>
    window.setTimeout(() => {
        ua?.stop();
        bridge?.close();
    }, delay);

async function start(): Promise<void> {
    $('title').textContent = t.title;
    status(t.connecting);

    const response = await fetch('/api/driver/v1/phone', {
        headers: authHeaders(),
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const { sip, target } = (await response.json()) as Phone;

    ua = new UA({
        sockets: [new WebSocketInterface(sip.ws)],
        uri: sip.uri,
        password: sip.password,
        register: false,
        session_timers: false,
    });
    const agent = ua;
    // Qo'ng'iroq faqat transport ulangandan keyin (aks holda INVITE yuborilmay bekor bo'ladi)
    await new Promise<void>((resolve, reject) => {
        agent.once('connected', () => resolve());
        agent.once('disconnected', () => reject(new Error('ws')));
        agent.start();
    });

    const host = sip.uri.split('@')[1];
    session = ua.call(`sip:${target}@${host}`, {
        mediaConstraints: { audio: true, video: false },
    });
    status(t.ringing);

    session.on('peerconnection', ({ peerconnection }) => {
        peerconnection.addEventListener('track', (e: RTCTrackEvent) => {
            const remote = $('remote') as HTMLAudioElement;
            remote.srcObject = e.streams[0];
            // Ba'zi Android WebView'larda autoplay o'zi boshlanmaydi — operator ovozi eshitilmay qolardi
            void remote.play().catch(() => undefined);
        });
    });
    // Asterisk navbat musiqasi uchun darhol javob beradi — bu hali operator emas
    session.on('confirmed', () => waitForOperator());
    session.on('ended', () => {
        stopAll();
        status(t.ended);
        close();
    });
    session.on('failed', (e) => {
        console.error('call failed', e.cause);
        stopAll();
        status(t.failed);
        close(2000);
    });
}

$('hangup').addEventListener('click', () => {
    if (session && !session.isEnded()) {
        session.terminate();
    } else {
        close(0);
    }
});

// Karnay ↔ qulog'ingizga tutiladigan dinamik (standart — karnay, mashinada qulay)
let speakerOn = true;
$('speaker').addEventListener('click', () => {
    speakerOn = !speakerOn;
    bridge?.speaker?.(speakerOn);
    $('speaker').classList.toggle('on', speakerOn);
});

$('mute').addEventListener('click', () => {
    if (!session) {
        return;
    }
    const muted = session.isMuted().audio;
    if (muted) {
        session.unmute({ audio: true });
    } else {
        session.mute({ audio: true });
    }
    $('mute').classList.toggle('on', !muted);
});

start().catch((e: unknown) => {
    console.error('call start failed', String(e));
    status(t.failed);
    close(2500);
});
