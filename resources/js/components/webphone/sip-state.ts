import {
    Invitation,
    Inviter,
    Registerer,
    RegistererState,
    SessionState,
    UserAgent,
} from 'sip.js';
import type { Session, Web } from 'sip.js';

/**
 * Operator webfoni holati (panel.1call.uz webphone asosida): globalThis'da turadi — Inertia sahifalari
 * almashganda ham ro'yxatdan o'tish va suhbat uzilmaydi, komponent qayta o'rnatilsa shu holatga ulanadi.
 */

export type SipStatus =
    | 'disconnected'
    | 'connecting'
    | 'connected'
    | 'registration_failed';
export type CallState = 'idle' | 'calling' | 'ringing' | 'active';

export type SipCredentials = {
    extension: string;
    uri: string;
    password: string;
    ws: string;
    /** STUN doim; TURN — server coturn bilan o'rnatilgan bo'lsa (vaqtinchalik login/parol) */
    ice_servers: RTCIceServer[];
    /** TURN paroli muddati (unix soniya); TURN yo'q bo'lsa null */
    ice_expires_at: number | null;
};

/** TURN paroli muddati tugashiga 1 soatdan kam qolsa yangilanadi */
const ICE_REFRESH_MARGIN_MS = 60 * 60_000;

export function iceExpiringSoon(sip: SipCredentials | null): boolean {
    return (
        !!sip?.ice_expires_at &&
        sip.ice_expires_at * 1000 - Date.now() < ICE_REFRESH_MARGIN_MS
    );
}

/** Kiruvchi qo'ng'iroq kartochkasi (server OperatorCallEvent) — buyurtma formasi shundan to'ldiriladi */
export type CallCard = {
    call_id: number;
    kind: 'client' | 'driver' | 'callback' | 'tts';
    phone: string | null;
    branch_id: number | null;
    number: string | null;
    client: {
        id: number;
        name: string | null;
        note: string | null;
        orders_count: number;
    } | null;
    driver: { id: number; callsign: string; name: string } | null;
    order_id: number | null;
};

/** Bir nechta varaq: faqat bittasi (leader) ichki raqamni ro'yxatdan o'tkazadi */
export type TabRole = 'pending' | 'leader' | 'follower';

type Oscillator = { stop: () => void };

export type GlobalSipState = {
    ua: UserAgent | null;
    session: Session | null;
    registerer: Registerer | null;
    remoteAudio: HTMLAudioElement | null;
    audioContext: AudioContext | null;
    ringOscillators: Oscillator[];
    timerInterval: ReturnType<typeof setInterval> | null;
    status: SipStatus;
    callState: CallState;
    activeCaller: string;
    duration: number;
    isMuted: boolean;
    isAudioEnabled: boolean;
    isOpen: boolean;
    listeners: Set<() => void>;
    registeredExtension: string;
    sipDomain: string;
    localStream: MediaStream | null;
    remoteStream: MediaStream | null;
    answering: boolean;
    /** Ulangan foydalanuvchi — boshqa foydalanuvchi kirsa eski ulanish yopiladi */
    userId: number | null;
    credentials: SipCredentials | null;
    /** Webfon komponenti beradi: serverdan yangi TURN login/paroli olinadi */
    refreshIce: (() => void) | null;
    displayName: string;
    /** destroyUA chaqirilgan — uzilish kutilgan, qayta ulanilmaydi */
    stopped: boolean;
    reconnectTimer: ReturnType<typeof setTimeout> | null;
    reconnectAttempts: number;
    tabRole: TabRole;
    /** Ulanish uzilgani uchun operator avtomatik "tanaffus"ga o'tkazilgan — qayta ulanganda "tayyor" qaytadi */
    autoPaused: boolean;
    /** Hozirgi suhbat kartochkasi (suhbat tugaguncha) */
    card: CallCard | null;
    /** Operator sahifasi hali ko'rmagan kartochka — boshqa sahifada kelgan bo'lsa ham forma to'ldiriladi */
    pendingCard: { card: CallCard; at: number } | null;
    notify: () => void;
    getAudioContext: () => AudioContext | null;
    playDtmfTone: (digit: string) => void;
    startCallSound: (type: 'ringing' | 'ringback') => void;
    stopCallSound: () => void;
    initUA: (
        sip: SipCredentials,
        displayName: string,
        userId?: number | null,
    ) => void;
    register: () => void;
    updateIceServers: (sip: SipCredentials) => void;
    scheduleReconnect: () => void;
    reconnectNow: () => void;
    setCard: (card: CallCard | null) => void;
    takePendingCard: () => CallCard | null;
    setupSession: (session: Session, fallbackCaller: string) => void;
    makeCall: (target: string, isVideo?: boolean) => void;
    answerCall: () => void;
    hangupCall: () => void;
    toggleMute: () => void;
    sendDTMF: (char: string) => void;
    destroyUA: () => void;
    resetCall: () => void;
};

const DTMF: Record<string, [number, number]> = {
    '1': [697, 1209],
    '2': [697, 1336],
    '3': [697, 1477],
    '4': [770, 1209],
    '5': [770, 1336],
    '6': [770, 1477],
    '7': [852, 1209],
    '8': [852, 1336],
    '9': [852, 1477],
    '*': [941, 1209],
    '0': [941, 1336],
    '#': [941, 1477],
};

function peerConnection(session: Session | null): RTCPeerConnection | null {
    const sdh = session?.sessionDescriptionHandler as
        | Web.SessionDescriptionHandler
        | undefined;

    return sdh?.peerConnection ?? null;
}

function streamOf(tracks: MediaStreamTrack[]): MediaStream | null {
    return tracks.length > 0 ? new MediaStream(tracks) : null;
}

type GlobalWithSip = typeof globalThis & { __DTAXI_SIP__?: GlobalSipState };

const existing = (globalThis as GlobalWithSip).__DTAXI_SIP__;

export const globalSip: GlobalSipState = existing ?? {
    ua: null,
    session: null,
    registerer: null,
    remoteAudio: null,
    audioContext: null,
    ringOscillators: [],
    timerInterval: null,
    status: 'disconnected',
    callState: 'idle',
    activeCaller: '',
    duration: 0,
    isMuted: false,
    isAudioEnabled: true,
    isOpen: false,
    listeners: new Set(),
    registeredExtension: '',
    sipDomain: '',
    localStream: null,
    remoteStream: null,
    answering: false,
    userId: null,
    credentials: null,
    refreshIce: null,
    displayName: '',
    stopped: false,
    reconnectTimer: null,
    reconnectAttempts: 0,
    tabRole: 'pending',
    autoPaused: false,
    card: null,
    pendingCard: null,

    notify() {
        this.listeners.forEach((cb) => {
            try {
                cb();
            } catch {
                // Bitta tinglovchi xatosi qolganlarini to'xtatmasin
            }
        });
    },

    getAudioContext() {
        if (typeof window === 'undefined') {
            return null;
        }
        if (!this.audioContext) {
            this.audioContext = new AudioContext();
        }
        if (this.audioContext.state === 'suspended') {
            void this.audioContext.resume();
        }

        return this.audioContext;
    },

    playDtmfTone(digit) {
        const freqs = DTMF[digit];
        const ctx = this.isAudioEnabled ? this.getAudioContext() : null;
        if (!freqs || !ctx) {
            return;
        }
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
        gain.connect(ctx.destination);
        for (const freq of freqs) {
            const osc = ctx.createOscillator();
            osc.frequency.value = freq;
            osc.connect(gain);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        }
    },

    startCallSound(type) {
        this.stopCallSound();
        const ctx = this.isAudioEnabled ? this.getAudioContext() : null;
        if (!ctx) {
            return;
        }
        if (type === 'ringing') {
            // Kiruvchi: uzluksiz ikki ohangli qo'ng'iroq
            const gain = ctx.createGain();
            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.connect(ctx.destination);
            const oscs = [440, 480].map((freq) => {
                const osc = ctx.createOscillator();
                osc.frequency.value = freq;
                osc.connect(gain);
                osc.start();

                return osc;
            });
            this.ringOscillators = oscs;
        } else {
            // Chiquvchi: har 4 soniyada uzun signal (ringback)
            const beep = () => {
                if (ctx.state === 'closed') {
                    return;
                }
                const gain = ctx.createGain();
                gain.gain.setValueAtTime(0.08, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(
                    0.001,
                    ctx.currentTime + 1.2,
                );
                const osc = ctx.createOscillator();
                osc.frequency.value = 425;
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 1.2);
            };
            beep();
            const interval = setInterval(beep, 4000);
            this.ringOscillators = [{ stop: () => clearInterval(interval) }];
        }
    },

    stopCallSound() {
        this.ringOscillators.forEach((osc) => {
            try {
                osc.stop();
            } catch {
                // Allaqachon to'xtagan
            }
        });
        this.ringOscillators = [];
    },

    initUA(sip, displayName, userId = null) {
        if (this.ua) {
            if (
                this.registeredExtension === sip.extension &&
                this.userId === userId
            ) {
                // Yangilangan TURN paroli bo'lsa — keyingi suhbatdan ishlatiladi
                this.updateIceServers(sip);
                // Uzilib qolgan bo'lsa — darhol qayta urinish
                if (this.status !== 'connected') {
                    this.reconnectNow();
                }

                return;
            }
            this.destroyUA();
        }

        this.stopped = false;
        this.userId = userId;
        this.credentials = sip;
        this.displayName = displayName;
        this.reconnectAttempts = 0;
        this.registeredExtension = sip.extension;
        this.sipDomain = sip.uri.split('@')[1] ?? window.location.hostname;

        const uri = UserAgent.makeURI(sip.uri);
        if (!uri) {
            this.status = 'registration_failed';
            this.notify();

            return;
        }

        const ua = new UserAgent({
            uri,
            transportOptions: {
                server: sip.ws,
                keepAliveInterval: 15,
                keepAliveDebounce: 10,
            },
            authorizationUsername: sip.extension,
            authorizationPassword: sip.password,
            displayName,
            // Server NAT ortida yoki ofis tarmog'i UDP'ni yopsa ovoz TURN orqali o'tadi
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: { iceServers: sip.ice_servers },
                // TURN (TLS) ajratishi ~1 s; STUN javob bermasa ham qo'ng'iroq kutib qolmasin
                iceGatheringTimeout: 3000,
            },
            gracefulShutdown: true,
            logBuiltinEnabled: false,
        });
        this.ua = ua;

        ua.delegate = {
            onConnect: () => {
                if (this.ua !== ua) {
                    return;
                }
                this.reconnectAttempts = 0;
                if (this.reconnectTimer) {
                    clearTimeout(this.reconnectTimer);
                    this.reconnectTimer = null;
                }
                this.status = 'connecting';
                this.notify();
                // Kompyuter uxlab qolgan bo'lsa TURN paroli eskirgan bo'lishi mumkin
                if (iceExpiringSoon(this.credentials)) {
                    this.refreshIce?.();
                }
                // Har ulanishda (birinchi va qayta) ichki raqam qayta ro'yxatdan o'tadi
                this.register();
            },
            onDisconnect: () => {
                if (this.ua !== ua) {
                    return;
                }
                this.status = 'disconnected';
                this.notify();
                this.scheduleReconnect();
            },
            onInvite: (invitation: Invitation) => {
                // Bir vaqtda bitta suhbat
                if (this.session) {
                    void invitation.reject({ statusCode: 486 });

                    return;
                }
                this.setupSession(invitation, '');
            },
        };

        this.status = 'connecting';
        this.notify();

        ua.start().catch(() => {
            if (this.ua !== ua) {
                return;
            }
            // Tarmoq yo'q yoki PBX javob bermadi — orqaga chekinish bilan qayta urinamiz
            this.status = 'registration_failed';
            this.notify();
            this.scheduleReconnect();
        });
    },

    register() {
        const ua = this.ua;
        if (!ua) {
            return;
        }
        if (this.registerer?.state === RegistererState.Terminated) {
            this.registerer = null;
        }
        if (!this.registerer) {
            const registerer = new Registerer(ua);
            this.registerer = registerer;
            registerer.stateChange.addListener((state) => {
                if (this.registerer !== registerer) {
                    return;
                }
                if (state === RegistererState.Registered) {
                    this.status = 'connected';
                } else if (state === RegistererState.Unregistered) {
                    this.status = 'disconnected';
                } else if (state === RegistererState.Terminated) {
                    this.status = 'registration_failed';
                }
                this.notify();
            });
        }
        this.registerer.register().catch(() => {
            this.status = 'registration_failed';
            this.notify();
        });
    },

    updateIceServers(sip) {
        if (!this.credentials || sip.extension !== this.registeredExtension) {
            return;
        }
        this.credentials = sip;
        // sip.js har yangi suhbatda shu sozlamani o'qiydi — qayta ro'yxatdan o'tish shart emas
        const options = this.ua?.configuration
            .sessionDescriptionHandlerFactoryOptions as
            | Web.SessionDescriptionHandlerFactoryOptions
            | undefined;
        if (options) {
            options.peerConnectionConfiguration = {
                iceServers: sip.ice_servers,
            };
        }
    },

    scheduleReconnect() {
        if (this.stopped || !this.ua || this.reconnectTimer) {
            return;
        }
        // 1, 2, 4, 8, 16, 30 soniya...
        const delay = Math.min(30_000, 1000 * 2 ** this.reconnectAttempts);
        this.reconnectAttempts += 1;
        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;
            this.reconnectNow();
        }, delay);
    },

    reconnectNow() {
        const ua = this.ua;
        if (this.stopped || !ua) {
            return;
        }
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        if (ua.isConnected()) {
            // Transport bor, ro'yxatdan o'tish yo'qolgan
            if (this.status !== 'connected') {
                this.register();
            }

            return;
        }
        this.status = 'connecting';
        this.notify();
        ua.reconnect().catch(() => {
            if (this.ua !== ua) {
                return;
            }
            this.status = 'disconnected';
            this.notify();
            this.scheduleReconnect();
        });
    },

    setCard(card) {
        this.card = card;
        if (card) {
            this.pendingCard = { card, at: Date.now() };
        }
        this.notify();
    },

    takePendingCard() {
        const pending = this.pendingCard;
        this.pendingCard = null;
        // Eski (10 daqiqadan oshgan) kartochka formaga tushmaydi
        if (!pending || Date.now() - pending.at > 10 * 60_000) {
            return null;
        }

        return pending.card;
    },

    setupSession(session, fallbackCaller) {
        this.session = session;
        this.isMuted = false;
        this.answering = false;

        const incoming = session instanceof Invitation;
        if (incoming) {
            this.callState = 'ringing';
            this.activeCaller =
                session.remoteIdentity.displayName ||
                session.remoteIdentity.uri.user ||
                fallbackCaller;
            this.startCallSound('ringing');
        } else {
            this.callState = 'calling';
            this.startCallSound('ringback');
        }
        this.isOpen = true;
        this.notify();

        const attachMedia = () => {
            const pc = peerConnection(session);
            if (!pc) {
                return;
            }
            const sync = () => {
                this.localStream = streamOf(
                    pc
                        .getSenders()
                        .map((s) => s.track)
                        .filter((t): t is MediaStreamTrack => !!t),
                );
                this.remoteStream = streamOf(
                    pc
                        .getReceivers()
                        .map((r) => r.track)
                        .filter((t): t is MediaStreamTrack => !!t),
                );
                if (this.remoteAudio && this.remoteStream) {
                    const current = this.remoteAudio
                        .srcObject as MediaStream | null;
                    const ids = (s: MediaStream | null) =>
                        (s?.getAudioTracks() ?? [])
                            .map((t) => t.id)
                            .sort()
                            .join();
                    if (ids(current) !== ids(this.remoteStream)) {
                        this.remoteAudio.srcObject = this.remoteStream;
                    }
                    // Brauzer avtomatik ovozni bloklasa — keyingi bosishda o'ynaydi
                    this.remoteAudio.play().catch(() => undefined);
                }
                this.notify();
            };
            pc.addEventListener('track', sync);
            sync();
        };

        session.stateChange.addListener((state) => {
            // Eskirgan suhbat hodisalari e'tiborsiz
            if (this.session !== session) {
                return;
            }
            if (
                state === SessionState.Establishing ||
                state === SessionState.Established
            ) {
                attachMedia();
            }

            switch (state) {
                case SessionState.Establishing:
                    if (!incoming) {
                        this.callState = 'calling';
                        this.notify();
                    }
                    break;
                case SessionState.Established:
                    this.stopCallSound();
                    this.callState = 'active';
                    this.duration = 0;
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                    }
                    this.timerInterval = setInterval(() => {
                        this.duration += 1;
                        this.notify();
                    }, 1000);
                    this.notify();
                    break;
                case SessionState.Terminating:
                case SessionState.Terminated:
                    this.resetCall();
                    break;
            }
        });
    },

    makeCall(target, isVideo = false) {
        this.isOpen = true;
        this.notify();

        const clean = target.trim();
        if (!clean || this.status !== 'connected' || this.session || !this.ua) {
            return;
        }
        this.getAudioContext();
        const uri = UserAgent.makeURI(`sip:${clean}@${this.sipDomain}`);
        if (!uri) {
            return;
        }

        const inviter = new Inviter(this.ua, uri, {
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: isVideo },
            },
        });
        this.activeCaller = clean;
        this.setupSession(inviter, clean);
        inviter.invite().catch(() => this.resetCall());
    },

    answerCall() {
        const session = this.session;
        if (
            !(session instanceof Invitation) ||
            this.callState !== 'ringing' ||
            this.answering
        ) {
            return;
        }
        this.answering = true;
        this.getAudioContext();
        // Bizning ichki raqamlar faqat ovozli — video so'ralmaydi
        session
            .accept({
                sessionDescriptionHandlerOptions: {
                    constraints: { audio: true, video: false },
                },
            })
            .catch(() => {
                this.answering = false;
                this.resetCall();
            });
    },

    hangupCall() {
        const session = this.session;
        if (!session) {
            return;
        }
        try {
            if (session.state === SessionState.Established) {
                void session.bye();
            } else if (
                session.state === SessionState.Establishing ||
                session.state === SessionState.Initial
            ) {
                if (session instanceof Invitation) {
                    void session.reject();
                } else if (session instanceof Inviter) {
                    void session.cancel();
                }
            }
        } catch {
            // Signal yuborilmasa ham mahalliy holat tozalanadi
        }
        this.resetCall();
    },

    resetCall() {
        this.localStream?.getTracks().forEach((t) => t.stop());
        this.localStream = null;
        this.remoteStream = null;
        this.stopCallSound();
        this.callState = 'idle';
        this.activeCaller = '';
        this.session = null;
        this.isMuted = false;
        this.answering = false;
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
        if (this.remoteAudio) {
            this.remoteAudio.srcObject = null;
        }
        this.notify();
    },

    toggleMute() {
        const pc =
            this.callState === 'active' ? peerConnection(this.session) : null;
        if (!pc) {
            return;
        }
        this.isMuted = !this.isMuted;
        pc.getSenders().forEach((sender) => {
            if (sender.track?.kind === 'audio') {
                sender.track.enabled = !this.isMuted;
            }
        });
        this.notify();
    },

    sendDTMF(char) {
        this.playDtmfTone(char);
        if (this.callState !== 'active' || !this.session) {
            return;
        }
        const sdh = this.session.sessionDescriptionHandler as
            | Web.SessionDescriptionHandler
            | undefined;
        sdh?.sendDtmf(char);
    },

    destroyUA() {
        this.stopped = true;
        this.registeredExtension = '';
        this.userId = null;
        this.credentials = null;
        this.card = null;
        this.pendingCard = null;
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        this.reconnectAttempts = 0;
        if (this.session) {
            this.hangupCall();
        }
        if (this.registerer) {
            try {
                this.registerer.unregister().catch(() => undefined);
                this.registerer.dispose().catch(() => undefined);
            } catch {
                // Ulanish allaqachon uzilgan
            }
            this.registerer = null;
        }
        if (this.ua) {
            try {
                this.ua.stop().catch(() => undefined);
            } catch {
                // Ulanish allaqachon uzilgan
            }
            this.ua = null;
        }
        this.status = 'disconnected';
        this.notify();
    },
};

(globalThis as GlobalWithSip).__DTAXI_SIP__ = globalSip;

// Internet qaytdi — kutmasdan qayta ulanamiz
if (typeof window !== 'undefined' && !existing) {
    window.addEventListener('online', () => {
        if (globalSip.ua && globalSip.status !== 'connected') {
            globalSip.reconnectAttempts = 0;
            globalSip.reconnectNow();
        }
    });
}
