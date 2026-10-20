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
};

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
    notify: () => void;
    getAudioContext: () => AudioContext | null;
    playDtmfTone: (digit: string) => void;
    startCallSound: (type: 'ringing' | 'ringback') => void;
    stopCallSound: () => void;
    initUA: (sip: SipCredentials, displayName: string) => void;
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

    initUA(sip, displayName) {
        if (this.ua) {
            if (this.registeredExtension === sip.extension) {
                return;
            }
            this.destroyUA();
        }

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
            // Asterisk shu tarmoqda — STUN/TURN shart emas
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: { iceServers: [] },
            },
            gracefulShutdown: true,
            logBuiltinEnabled: false,
        });
        this.ua = ua;

        ua.delegate = {
            onConnect: () => {
                this.status = 'connecting';
                this.notify();
            },
            onDisconnect: () => {
                this.status = 'disconnected';
                this.notify();
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

        ua.start()
            .then(() => {
                const registerer = new Registerer(ua);
                this.registerer = registerer;
                registerer.stateChange.addListener((state) => {
                    if (state === RegistererState.Registered) {
                        this.status = 'connected';
                    } else if (state === RegistererState.Unregistered) {
                        this.status = 'disconnected';
                    } else if (state === RegistererState.Terminated) {
                        this.status = 'registration_failed';
                    }
                    this.notify();
                });

                return registerer.register();
            })
            .catch(() => {
                this.status = 'registration_failed';
                this.notify();
            });
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
        this.registeredExtension = '';
        if (this.session) {
            this.hangupCall();
        }
        if (this.registerer) {
            try {
                void this.registerer.unregister();
                void this.registerer.dispose();
            } catch {
                // Ulanish allaqachon uzilgan
            }
            this.registerer = null;
        }
        if (this.ua) {
            try {
                void this.ua.stop();
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
