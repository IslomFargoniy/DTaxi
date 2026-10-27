/**
 * Mijoz ilovasidan qo'ng'iroq (qaror #72): ilova WebView'ida ochiladi, target() — driver yoki dispatcher.
 * Server har qo'ng'iroqqa vaqtinchalik ichki raqam (cl{N}) beradi; raqamlar ikki tomonga ham ko'rinmaydi.
 */
import { UA, WebSocketInterface } from 'jssip';
import type { RTCSession } from 'jssip/lib/RTCSession';
import { attachRemote, iceGathering } from './lib/call-media';

type Bridge = {
    token(): string;
    locale(): string;
    close(): void;
    speaker?(on: boolean): void;
    /** driver | dispatcher */
    target(): string;
    /** Ekrandagi nom: haydovchi ismi (ilova biladi) */
    title?(): string;
};
type Grant = {
    sip: {
        uri: string;
        password: string;
        ws: string;
        ice_servers: RTCIceServer[];
    };
    target: string;
};

const TEXT = {
    uz: {
        driver: 'Haydovchi',
        dispatcher: 'Dispetcher',
        connecting: 'Ulanmoqda…',
        ringing: 'Chaqirilmoqda…',
        waiting: 'Dispetcher javobini kutmoqda…',
        talking: 'Gaplashmoqda',
        ended: 'Qo’ng’iroq tugadi',
        busy: 'Band',
        noAnswer: 'Javob bermadi',
        failed: 'Ulanib bo’lmadi',
        mute: 'Ovozni o‘chirish',
        speaker: 'Karnay',
    },
    oz: {
        driver: 'Ҳайдовчи',
        dispatcher: 'Диспетчер',
        connecting: 'Уланмоқда…',
        ringing: 'Чақирилмоқда…',
        waiting: 'Диспетчер жавобини кутмоқда…',
        talking: 'Гаплашмоқда',
        ended: 'Қўнғироқ тугади',
        busy: 'Банд',
        noAnswer: 'Жавоб бермади',
        failed: 'Уланиб бўлмади',
        mute: 'Овозни ўчириш',
        speaker: 'Карнай',
    },
    ru: {
        driver: 'Водитель',
        dispatcher: 'Диспетчер',
        connecting: 'Подключение…',
        ringing: 'Вызов…',
        waiting: 'Ожидание ответа диспетчера…',
        talking: 'Разговор',
        ended: 'Звонок завершён',
        busy: 'Занято',
        noAnswer: 'Не отвечает',
        failed: 'Не удалось подключиться',
        mute: 'Без звука',
        speaker: 'Динамик',
    },
    en: {
        driver: 'Driver',
        dispatcher: 'Dispatcher',
        connecting: 'Connecting…',
        ringing: 'Calling…',
        waiting: 'Waiting for the dispatcher…',
        talking: 'Talking',
        ended: 'Call ended',
        busy: 'Busy',
        noAnswer: 'No answer',
        failed: 'Could not connect',
        mute: 'Mute',
        speaker: 'Speaker',
    },
};

const CAR_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M5 17h14M5 17v2M19 17v2M4 13l1.6-5.2A2 2 0 0 1 7.5 6.4h9a2 2 0 0 1 1.9 1.4L20 13"/>' +
    '<rect x="3" y="13" width="18" height="4" rx="1.5"/><circle cx="7.5" cy="15" r=".6"/><circle cx="16.5" cy="15" r=".6"/></svg>';

// window.Dtaxi turi driver-call.ts dagi global e'lon bilan to'qnashmasin — shu yerda alohida
const bridge = (window as unknown as { Dtaxi?: Bridge }).Dtaxi;
const locale = bridge?.locale() ?? 'uz';
const t = TEXT[(locale in TEXT ? locale : 'uz') as keyof typeof TEXT];
const target = bridge?.target() === 'driver' ? 'driver' : 'dispatcher';
const $ = (id: string) => document.getElementById(id) as HTMLElement;
const status = (text: string) => ($('status').textContent = text);
const remote = () => $('remote') as HTMLAudioElement;
let session: RTCSession | null = null;
let ua: UA | null = null;
let timer = 0;
let poll = 0;

const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${bridge?.token() ?? ''}`,
});

function startTimer(): void {
    if (timer) {
        return;
    }
    window.clearInterval(poll);
    $('avatar').classList.remove('live');
    status(t.talking);
    const started = Date.now();
    timer = window.setInterval(() => {
        const s = Math.floor((Date.now() - started) / 1000);
        $('timer').textContent =
            `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }, 1000);
}

/** Dispetcher navbatida Asterisk darhol javob beradi (gudok) — operator javobini server holatidan bilamiz */
function waitForOperator(): void {
    status(t.waiting);
    $('avatar').classList.add('live');
    poll = window.setInterval(() => {
        fetch('/api/client/v1/calls/current', { headers: headers() })
            .then((r) => (r.ok ? r.json() : null))
            .then((call: { status: string | null } | null) => {
                if (call?.status === 'answered') {
                    startTimer();
                }
            })
            .catch(() => undefined);
    }, 1000);
}

const close = (delay = 1500) =>
    window.setTimeout(() => {
        ua?.stop();
        bridge?.close();
    }, delay);

function failure(cause: string | undefined): string {
    switch (cause) {
        case 'Busy':
        case 'Rejected':
            return t.busy;
        case 'Unavailable':
        case 'No Answer':
        case 'Request Timeout':
            return t.noAnswer;
        default:
            return t.failed;
    }
}

async function start(): Promise<void> {
    $('title').textContent =
        (target === 'driver' && bridge?.title?.()) || t[target];
    $('mute-label').textContent = t.mute;
    $('speaker-label').textContent = t.speaker;
    if (target === 'driver') {
        $('avatar').innerHTML = CAR_ICON;
    }
    status(t.connecting);

    const response = await fetch('/api/client/v1/calls', {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ target }),
    });
    if (response.status === 422) {
        // Faol safar tugagan, dispetcher ishlamayapti va h.k. — server sababi ko'rsatiladi
        const body = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        status(body.errors?.target?.[0] ?? body.message ?? t.failed);
        close(3500);

        return;
    }
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const { sip } = (await response.json()) as Grant;

    ua = new UA({
        sockets: [new WebSocketInterface(sip.ws)],
        uri: sip.uri,
        password: sip.password,
        register: false,
        session_timers: false,
    });
    const agent = ua;
    await new Promise<void>((resolve, reject) => {
        agent.once('connected', () => resolve());
        agent.once('disconnected', () => reject(new Error('ws')));
        agent.start();
    });

    const host = sip.uri.split('@')[1];
    const iceReady = iceGathering(sip.ice_servers);
    session = ua.call(`sip:${target}@${host}`, {
        mediaConstraints: { audio: true, video: false },
        pcConfig: { iceServers: sip.ice_servers },
        eventHandlers: {
            peerconnection: ({ peerconnection }) =>
                attachRemote(peerconnection, remote()),
            icecandidate: iceReady,
        },
    });
    if (session.connection) {
        attachRemote(session.connection, remote());
    }

    session.on('progress', () => status(t.ringing));
    // Haydovchi: javob berganda ulanadi. Dispetcher: navbatda gudok uchun darhol javob beriladi
    session.on('confirmed', () =>
        target === 'driver' ? startTimer() : waitForOperator(),
    );
    session.on('ended', () => {
        window.clearInterval(timer);
        window.clearInterval(poll);
        $('avatar').classList.remove('live');
        status(t.ended);
        close();
    });
    session.on('failed', (e) => {
        window.clearInterval(poll);
        $('avatar').classList.remove('live');
        status(failure(e.cause));
        close(2500);
    });
}

$('hangup').addEventListener('click', () => {
    if (session && !session.isEnded()) {
        session.terminate();
    } else {
        close(0);
    }
});

// Mijoz telefonni qulog'iga tutadi — standart quloq dinamigi
let speakerOn = false;
$('speaker').classList.remove('on');
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
