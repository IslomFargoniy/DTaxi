import { useEcho } from '@laravel/echo-react';
import {
    Activity,
    Delete,
    Mic,
    MicOff,
    Minimize2,
    Phone,
    PhoneMissed,
    PhoneOff,
    ShieldAlert,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTrans } from '@/hooks/use-trans';
import { getJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    missed as missedRoute,
    show as showRoute,
    status as statusRoute,
} from '@/routes/operator/phone';
import { globalSip } from './sip-state';
import type { CallCard, SipCredentials } from './sip-state';
import {
    resignLeadership,
    startLeaderElection,
    stopLeaderElection,
    takeLeadership,
} from './tab-leader';
import { formatPhone } from '@/lib/format';

export type { CallCard } from './sip-state';

export type OperatorStatus = 'offline' | 'ready' | 'paused';

/** Uzilish shuncha davom etsa operator "tanaffus"ga o'tkaziladi (qisqa uzilishda navbat buzilmasin) */
const AUTO_PAUSE_MS = 3000;

type MissedCall = {
    id: number;
    phone: string;
    client: string | null;
    started_at: string;
};

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
const LAUNCHER = 56;
const PANEL_WIDTH = 310;

type Point = { x: number; y: number };

function loadPoint(key: string): Point | null {
    try {
        const raw = localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as Point) : null;
    } catch {
        return null;
    }
}

function savePoint(key: string, point: Point | null) {
    try {
        localStorage.setItem(key, JSON.stringify(point));
    } catch {
        // Saqlab bo'lmasa — standart joyda
    }
}

/** Oyna ekrandan chiqmasin; ekran oynadan tor bo'lsa — chap/yuqori chetga yopishadi */
export function clamp(point: Point, width: number, height: number): Point {
    return {
        x: Math.max(8, Math.min(point.x, window.innerWidth - width - 8)),
        y: Math.max(8, Math.min(point.y, window.innerHeight - height - 8)),
    };
}

/**
 * Sudraladigan element: 5 px dan kam siljish — oddiy bosish. Joyi brauzerda saqlanadi.
 */
function useDraggable(storageKey: string, width: number, height: number) {
    const [point, setPoint] = useState<Point | null>(() =>
        loadPoint(storageKey),
    );
    const drag = useRef<{
        dx: number;
        dy: number;
        startX: number;
        startY: number;
    } | null>(null);
    const moved = useRef(false);
    const latest = useRef(point);

    // Saqlangan joy kichikroq ekranda yoki oyna o'lchami o'zgarganda chetdan chiqib qolmasin
    useEffect(() => {
        const fit = () =>
            setPoint((p) => {
                if (!p) {
                    return p;
                }
                const next = clamp(p, width, height);

                return next.x === p.x && next.y === p.y ? p : next;
            });
        fit();
        window.addEventListener('resize', fit);

        return () => window.removeEventListener('resize', fit);
    }, [width, height]);

    const onPointerDown = (e: React.PointerEvent<HTMLElement>) => {
        if (e.button !== 0) {
            return;
        }
        const box = (e.currentTarget.closest('[data-drag-root]') ??
            e.currentTarget) as HTMLElement;
        const rect = box.getBoundingClientRect();
        drag.current = {
            dx: e.clientX - rect.left,
            dy: e.clientY - rect.top,
            startX: e.clientX,
            startY: e.clientY,
        };
        moved.current = false;
        e.currentTarget.setPointerCapture(e.pointerId);
    };
    const onPointerMove = (e: React.PointerEvent<HTMLElement>) => {
        const d = drag.current;
        if (!d) {
            return;
        }
        if (
            !moved.current &&
            Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < 5
        ) {
            return;
        }
        moved.current = true;
        const next = clamp(
            { x: e.clientX - d.dx, y: e.clientY - d.dy },
            width,
            height,
        );
        latest.current = next;
        setPoint(next);
    };
    const onPointerUp = () => {
        if (drag.current && moved.current) {
            savePoint(storageKey, latest.current);
        }
        drag.current = null;
    };

    return {
        point,
        moved,
        handlers: { onPointerDown, onPointerMove, onPointerUp },
    };
}

/** Global holatning nusxasi — render shu nusxadan o'qiydi (global obyektdan emas, React Compiler keshi uchun) */
function snapshot() {
    return {
        status: globalSip.status,
        callState: globalSip.callState,
        isOpen: globalSip.isOpen,
        isMuted: globalSip.isMuted,
        isAudioEnabled: globalSip.isAudioEnabled,
        duration: globalSip.duration,
        activeCaller: globalSip.activeCaller,
        tabRole: globalSip.tabRole,
        card: globalSip.card,
    };
}

function formatDuration(sec: number): string {
    return `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`;
}

/**
 * Operator webfoni (panel.1call.uz webphone'idan ko'chirilgan, Dtaxi call-center'iga moslangan):
 * sip.js orqali WebRTC ichki raqam, suzuvchi sudraladigan oyna, terish paneli va DTMF,
 * qo'ng'iroq ovozlari, operator holati, kiruvchi qo'ng'iroq kartochkasi va javobsizlar.
 * Layout'da turadi — sahifa almashsa ham suhbat uzilmaydi.
 */
export function WebPhone({
    userId,
    userName,
}: {
    userId: number;
    userName: string;
}) {
    const { t } = useTrans();
    const [sip, setSip] = useState<SipCredentials | null>(null);
    const [operatorStatus, setOperatorStatus] =
        useState<OperatorStatus>('offline');
    const [phone, setPhone] = useState(snapshot);
    const [dialNumber, setDialNumber] = useState('');
    const [dtmfBuffer, setDtmfBuffer] = useState('');
    const [missed, setMissed] = useState<MissedCall[]>([]);
    const [showMissed, setShowMissed] = useState(false);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    const launcher = useDraggable(
        'webphone-button-position',
        LAUNCHER,
        LAUNCHER,
    );
    const panel = useDraggable('webphone-window-position', PANEL_WIDTH, 480);

    const {
        status,
        callState,
        isOpen,
        isMuted,
        isAudioEnabled,
        duration,
        activeCaller,
        tabRole,
        card,
    } = phone;

    // Global holatga obuna: har o'zgarishda qayta chizish
    useEffect(() => {
        const sync = () => {
            setPhone(snapshot());
            if (globalSip.callState === 'idle') {
                setDtmfBuffer('');
            }
        };
        globalSip.listeners.add(sync);

        return () => {
            globalSip.listeners.delete(sync);
        };
    }, []);

    const loadMissed = useCallback(() => {
        getJson<MissedCall[]>(missedRoute.url())
            .then(setMissed)
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        // Shu varaqda avval boshqa foydalanuvchi ulangan bo'lsa — uning ulanishi yopiladi
        if (globalSip.ua && globalSip.userId !== userId) {
            globalSip.destroyUA();
        }
        getJson<{ sip: SipCredentials | null; status: OperatorStatus }>(
            showRoute.url(),
        )
            .then((r) => {
                setSip(r.sip);
                setOperatorStatus(r.status);
                if (!r.sip) {
                    // Ichki raqam yo'q — eski ulanish qolib ketmasin
                    globalSip.destroyUA();
                    stopLeaderElection();
                }
            })
            .catch(() => undefined);
        loadMissed();
    }, [loadMissed, userId]);

    // Bir nechta varaq: telefon faqat bittasida ro'yxatdan o'tadi
    useEffect(() => {
        if (sip) {
            startLeaderElection();
        }
    }, [sip]);

    // Ro'yxatdan o'tish (allaqachon shu raqam bilan ulangan bo'lsa — qayta ulanmaydi)
    useEffect(() => {
        if (!sip || tabRole !== 'leader') {
            return;
        }
        if (audioRef.current) {
            globalSip.remoteAudio = audioRef.current;
        }
        globalSip.initUA(sip, userName, userId);
    }, [sip, userName, userId, tabRole]);

    // Ulanish uzildi — operatorga qo'ng'iroq yo'naltirilmasin ("tanaffus"); qayta ulanganda "tayyor" qaytadi
    useEffect(() => {
        if (tabRole !== 'leader' || !sip) {
            return;
        }
        if (status === 'connected') {
            if (globalSip.autoPaused) {
                globalSip.autoPaused = false;
                postJson<{ status: OperatorStatus }>(statusRoute.url(), {
                    status: 'ready',
                })
                    .then((r) => setOperatorStatus(r.status))
                    .catch(() => undefined);
            }

            return;
        }
        if (operatorStatus !== 'ready') {
            return;
        }
        const id = window.setTimeout(() => {
            if (globalSip.status === 'connected' || globalSip.stopped) {
                return;
            }
            globalSip.autoPaused = true;
            postJson<{ status: OperatorStatus }>(statusRoute.url(), {
                status: 'paused',
            })
                .then((r) => setOperatorStatus(r.status))
                .catch(() => undefined);
        }, AUTO_PAUSE_MS);

        return () => window.clearTimeout(id);
    }, [status, operatorStatus, tabRole, sip]);

    // Suhbat paytida sahifani yopish/yangilashdan ogohlantirish
    useEffect(() => {
        const onBeforeUnload = (e: BeforeUnloadEvent) => {
            if (globalSip.callState !== 'idle') {
                e.preventDefault();
            }
        };
        const onPageHide = () => {
            resignLeadership();
            globalSip.destroyUA();
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        window.addEventListener('pagehide', onPageHide);

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            window.removeEventListener('pagehide', onPageHide);
        };
    }, []);

    // Server: qo'ng'iroq kartochkasi, holat o'zgarishi, javobsiz
    useEcho<{ type: string; status?: OperatorStatus } & Partial<CallCard>>(
        `user.${userId}`,
        'OperatorCallEvent',
        (e) => {
            if (e.type === 'incoming' && e.call_id) {
                // Qo'ng'iroq telefoni ochiq varaqqa keladi — boshqa varaqlar kartochka ochmaydi
                if (globalSip.tabRole !== 'leader') {
                    return;
                }
                const c = e as unknown as CallCard;
                globalSip.isOpen = true;
                // Operator sahifasi ochilmagan bo'lsa — keyin o'qib oladi (pendingCard)
                globalSip.setCard(c);
                window.dispatchEvent(
                    new CustomEvent('dtaxi:call-card', { detail: c }),
                );
            } else if (e.type === 'status' && e.status) {
                setOperatorStatus(e.status);
            } else if (e.type === 'ended') {
                loadMissed();
            }
        },
        [loadMissed],
    );

    // Suhbat tugagach kartochka yopiladi
    useEffect(() => {
        if (callState === 'idle' && card) {
            const id = window.setTimeout(() => {
                globalSip.card = null;
                globalSip.notify();
            }, 300);

            return () => window.clearTimeout(id);
        }
    }, [callState, card]);

    const changeStatus = (next: OperatorStatus) => {
        // Operator o'zi tanladi — avtomatik tiklash bekor
        globalSip.autoPaused = false;
        postJson<{ status: OperatorStatus }>(statusRoute.url(), {
            status: next,
        })
            .then((r) => setOperatorStatus(r.status))
            .catch(() => undefined);
    };

    const open = (value: boolean) => {
        globalSip.getAudioContext();
        globalSip.isOpen = value;
        globalSip.notify();
    };

    const call = (target: string) => {
        // Telefon, ichki raqam yoki pozivnoy — server o'zi aniqlaydi
        const clean = target.replace(/[^0-9A-Za-z*#]/g, '');
        if (clean.length < 2) {
            return;
        }
        globalSip.makeCall(clean);
        setShowMissed(false);
    };

    const pressKey = (char: string) => {
        globalSip.sendDTMF(char);
        if (globalSip.callState === 'active') {
            setDtmfBuffer((b) => b + char);
        } else {
            setDialNumber((n) => n + char);
        }
    };

    if (!sip) {
        return (
            <audio
                ref={audioRef}
                id="webphone-remote-audio"
                autoPlay
                playsInline
                className="hidden"
            />
        );
    }

    const follower = tabRole === 'follower';
    const connected = status === 'connected';
    const who = card?.driver
        ? `${t('Driver')} #${card.driver.callsign} — ${card.driver.name}`
        : (card?.client?.name ?? formatPhone(card?.phone ?? activeCaller));

    const statusText = follower
        ? t('Phone is open in another tab')
        : {
              connected: t('Extension :extension is active', {
                  extension: sip.extension,
              }),
              connecting: t('Connecting…'),
              disconnected: t('Disconnected — reconnecting…'),
              registration_failed: t('Connection error — retrying…'),
          }[status];

    const fromLauncher = launcher.point
        ? clamp(
              {
                  x: launcher.point.x + LAUNCHER - PANEL_WIDTH,
                  y: launcher.point.y - 480 + LAUNCHER,
              },
              PANEL_WIDTH,
              480,
          )
        : null;
    const panelStyle: React.CSSProperties = panel.point
        ? { left: panel.point.x, top: panel.point.y }
        : fromLauncher
          ? { left: fromLauncher.x, top: fromLauncher.y }
          : { right: 24, bottom: 96 };

    return (
        <>
            <audio
                ref={audioRef}
                id="webphone-remote-audio"
                autoPlay
                playsInline
                className="hidden"
            />

            {/* Chaqiruv tugmasi — standart o'ng pastda, chat tugmasidan yuqorida; sudrab ko'chiriladi */}
            {!isOpen && (
                <button
                    type="button"
                    data-drag-root
                    aria-label={t('Phone')}
                    title={statusText}
                    {...launcher.handlers}
                    onClick={() => {
                        if (!launcher.moved.current) {
                            open(true);
                        }
                    }}
                    style={
                        launcher.point
                            ? { left: launcher.point.x, top: launcher.point.y }
                            : { right: 24, bottom: 96 }
                    }
                    className={cn(
                        'fixed z-50 flex size-14 cursor-grab touch-none items-center justify-center rounded-full border-2 text-white shadow-2xl transition-colors active:cursor-grabbing',
                        callState === 'ringing'
                            ? 'animate-pulse border-red-300 bg-red-600 ring-4 ring-red-500/50'
                            : callState !== 'idle'
                              ? 'border-amber-300 bg-amber-500'
                              : connected
                                ? 'border-background bg-emerald-600 ring-4 ring-emerald-500/40 hover:bg-emerald-500'
                                : 'border-background bg-zinc-500 ring-4 ring-zinc-400/30',
                    )}
                >
                    <Phone
                        className={cn(
                            'size-6',
                            callState !== 'idle' && 'animate-pulse',
                        )}
                    />
                    {missed.length > 0 && (
                        <span className="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-white text-[11px] font-bold text-red-600 shadow-sm ring-2 ring-red-600">
                            {missed.length}
                        </span>
                    )}
                </button>
            )}

            {isOpen && (
                <div
                    data-drag-root
                    style={panelStyle}
                    className="fixed z-50 flex w-[310px] max-w-[calc(100vw-16px)] flex-col overflow-hidden rounded-2xl border-2 border-primary/30 bg-card text-card-foreground shadow-2xl select-none dark:bg-zinc-900"
                >
                    {/* Sarlavha — ushlab sudraladi */}
                    <div
                        {...panel.handlers}
                        className="flex cursor-grab touch-none items-center justify-between border-b bg-muted px-4 py-3 active:cursor-grabbing"
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <span
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    connected
                                        ? 'bg-emerald-500'
                                        : 'bg-rose-500',
                                )}
                            />
                            <span className="truncate text-xs font-semibold text-muted-foreground">
                                {statusText}
                            </span>
                        </div>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                aria-label={t('Missed calls')}
                                title={t('Missed calls')}
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => setShowMissed(!showMissed)}
                                className={cn(
                                    'relative rounded p-1 text-muted-foreground hover:bg-background',
                                    showMissed && 'bg-background',
                                )}
                            >
                                <PhoneMissed className="size-3.5" />
                                {missed.length > 0 && (
                                    <span className="absolute -top-1 -right-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-red-600 px-0.5 text-[9px] font-bold text-white">
                                        {missed.length}
                                    </span>
                                )}
                            </button>
                            <button
                                type="button"
                                aria-label={
                                    isAudioEnabled
                                        ? t('Disable sounds')
                                        : t('Enable sounds')
                                }
                                title={
                                    isAudioEnabled
                                        ? t('Disable sounds')
                                        : t('Enable sounds')
                                }
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => {
                                    globalSip.isAudioEnabled =
                                        !globalSip.isAudioEnabled;
                                    if (!globalSip.isAudioEnabled) {
                                        globalSip.stopCallSound();
                                    }
                                    globalSip.notify();
                                }}
                                className="rounded p-1 text-muted-foreground hover:bg-background"
                            >
                                {isAudioEnabled ? (
                                    <Volume2 className="size-3.5" />
                                ) : (
                                    <VolumeX className="size-3.5 text-rose-400" />
                                )}
                            </button>
                            <button
                                type="button"
                                aria-label={t('Minimize')}
                                title={t('Minimize')}
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => open(false)}
                                className="rounded p-1 text-muted-foreground hover:bg-background"
                            >
                                <Minimize2 className="size-3.5" />
                            </button>
                        </div>
                    </div>

                    {/* Operator holati: "Tayyor" bo'lsa navbatdagi qo'ng'iroqlar shu operatorga keladi */}
                    <div className="flex gap-1 border-b px-3 py-2">
                        {(['ready', 'paused', 'offline'] as const).map((s) => (
                            <button
                                key={s}
                                type="button"
                                disabled={s === 'ready' && !connected}
                                onClick={() => changeStatus(s)}
                                className={cn(
                                    'h-7 flex-1 rounded-md border text-xs font-semibold transition-colors disabled:opacity-40',
                                    operatorStatus === s
                                        ? s === 'ready'
                                            ? 'border-emerald-600 bg-emerald-600 text-white'
                                            : s === 'paused'
                                              ? 'border-amber-500 bg-amber-500 text-white'
                                              : 'border-zinc-500 bg-zinc-500 text-white'
                                        : 'bg-background hover:bg-muted',
                                )}
                            >
                                {t(
                                    s === 'ready'
                                        ? 'Ready'
                                        : s === 'paused'
                                          ? 'Paused'
                                          : 'Offline',
                                )}
                            </button>
                        ))}
                    </div>

                    {/* Mikrofon faqat xavfsiz (https) sahifada — http'da qo'ng'iroq keladi, lekin javob berib bo'lmaydi */}
                    {typeof window !== 'undefined' &&
                        !window.isSecureContext && (
                            <div className="flex items-start gap-2 border-b border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-400">
                                <ShieldAlert className="size-4 shrink-0" />
                                <span className="flex-1">
                                    {t(
                                        'The microphone works only on a secure (https) address.',
                                    )}{' '}
                                    <a
                                        className="font-semibold underline"
                                        href={`https://${window.location.host}${window.location.pathname}`}
                                    >
                                        {t('Open the https version')}
                                    </a>
                                </span>
                            </div>
                        )}

                    {follower && (
                        <div className="flex items-center gap-2 border-b border-sky-200 bg-sky-50 p-2.5 text-xs text-sky-800 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-300">
                            <span className="flex-1">
                                {t(
                                    'Phone is open in another tab. Calls ring there.',
                                )}
                            </span>
                            <button
                                type="button"
                                onClick={() => takeLeadership()}
                                className="shrink-0 rounded-md border border-sky-300 bg-background px-2 py-1 font-semibold hover:bg-muted dark:border-sky-800"
                            >
                                {t('Use here')}
                            </button>
                        </div>
                    )}

                    {!follower && status === 'registration_failed' && (
                        <div className="flex items-start gap-2 border-b border-red-200 bg-red-50 p-2.5 text-xs text-red-600 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-400">
                            <ShieldAlert className="size-4 shrink-0" />
                            <span>
                                {t(
                                    'The phone could not connect to the PBX. Retrying automatically…',
                                )}
                            </span>
                        </div>
                    )}

                    {showMissed ? (
                        <div className="max-h-[360px] min-h-[200px] overflow-auto p-1">
                            <div className="px-2 py-1 text-xs font-semibold">
                                {t('Missed calls')}
                            </div>
                            {missed.length === 0 && (
                                <div className="px-2 py-6 text-center text-xs text-muted-foreground">
                                    {t('No missed calls')}
                                </div>
                            )}
                            {missed.map((m) => (
                                <div
                                    key={m.id}
                                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-muted"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm">
                                            {m.client ?? formatPhone(m.phone)}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {m.client &&
                                                `${formatPhone(m.phone)} · `}
                                            {new Date(
                                                m.started_at,
                                            ).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-label={t('Call back')}
                                        title={t('Call back')}
                                        disabled={
                                            !connected || callState !== 'idle'
                                        }
                                        onClick={() => call(m.phone)}
                                        className="flex size-8 items-center justify-center rounded-full bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-40"
                                    >
                                        <Phone className="size-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <>
                            {/* Ekran: raqam yoki suhbat */}
                            <div className="flex min-h-[90px] flex-col items-center justify-center border-b bg-muted/30 p-5">
                                {callState === 'idle' ? (
                                    <div className="flex w-full items-center justify-between">
                                        <input
                                            type="text"
                                            inputMode="tel"
                                            autoComplete="off"
                                            value={dialNumber}
                                            aria-label={t(
                                                'Number or extension',
                                            )}
                                            onChange={(e) =>
                                                setDialNumber(
                                                    e.target.value.replace(
                                                        /[^0-9A-Za-z*#+\s-]/g,
                                                        '',
                                                    ),
                                                )
                                            }
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    call(dialNumber);
                                                }
                                            }}
                                            placeholder={t(
                                                'Number, extension or callsign',
                                            )}
                                            className="w-full border-none bg-transparent pr-2 text-xl font-bold tracking-wider outline-none placeholder:text-sm placeholder:font-normal placeholder:tracking-normal placeholder:text-muted-foreground"
                                        />
                                        {dialNumber && (
                                            <button
                                                type="button"
                                                aria-label={t('Delete')}
                                                onClick={() =>
                                                    setDialNumber((n) =>
                                                        n.slice(0, -1),
                                                    )
                                                }
                                                className="rounded-full p-1.5 text-muted-foreground hover:bg-muted"
                                            >
                                                <Delete className="size-4" />
                                            </button>
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex w-full flex-col items-center gap-1.5">
                                        <span
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wider uppercase',
                                                callState === 'ringing'
                                                    ? 'animate-pulse bg-rose-100 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400'
                                                    : callState === 'calling'
                                                      ? 'bg-blue-100 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400'
                                                      : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400',
                                            )}
                                        >
                                            {callState === 'ringing' &&
                                                t('Incoming call')}
                                            {callState === 'calling' &&
                                                t('Calling…')}
                                            {callState === 'active' &&
                                                t('Active call')}
                                        </span>
                                        <span className="text-center text-lg font-bold tracking-wide">
                                            {who}
                                        </span>
                                        {card?.client && (
                                            <span className="text-xs text-muted-foreground">
                                                {formatPhone(card.phone)} ·{' '}
                                                {t('Orders')}:{' '}
                                                {card.client.orders_count}
                                            </span>
                                        )}
                                        {card?.client?.note && (
                                            <span className="text-center text-xs text-amber-600 dark:text-amber-400">
                                                {card.client.note}
                                            </span>
                                        )}
                                        {callState === 'active' && (
                                            <div className="mt-1 flex flex-col items-center gap-1">
                                                <div className="flex items-center gap-1 font-mono text-sm font-semibold tracking-widest text-emerald-600 dark:text-emerald-400">
                                                    <Activity className="size-3.5 animate-pulse" />
                                                    <span>
                                                        {formatDuration(
                                                            duration,
                                                        )}
                                                    </span>
                                                </div>
                                                {dtmfBuffer && (
                                                    <div className="mt-1 flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 font-mono text-sm tracking-widest">
                                                        <span>
                                                            {dtmfBuffer}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            aria-label={t(
                                                                'Clear',
                                                            )}
                                                            onClick={() =>
                                                                setDtmfBuffer(
                                                                    '',
                                                                )
                                                            }
                                                            className="ml-1 text-xs text-muted-foreground hover:text-rose-500"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Terish paneli (suhbatda — DTMF) */}
                            <div className="grid grid-cols-3 justify-items-center gap-3 p-4">
                                {KEYS.map((char) => (
                                    <button
                                        key={char}
                                        type="button"
                                        onClick={() => pressKey(char)}
                                        className="flex size-13 items-center justify-center rounded-full border bg-muted/50 text-lg font-bold transition-colors hover:bg-muted active:bg-muted-foreground/20"
                                    >
                                        {char}
                                    </button>
                                ))}
                            </div>

                            {/* Boshqaruv */}
                            <div className="flex min-h-[70px] items-center justify-center gap-6 border-t bg-muted/40 p-4">
                                {callState === 'idle' ? (
                                    <button
                                        type="button"
                                        aria-label={t('Call')}
                                        title={t('Call')}
                                        onClick={() => call(dialNumber)}
                                        disabled={!connected || !dialNumber}
                                        className={cn(
                                            'flex size-13 items-center justify-center rounded-full border shadow-lg transition-transform active:scale-95',
                                            connected && dialNumber
                                                ? 'border-emerald-400 bg-emerald-500 text-white hover:bg-emerald-400'
                                                : 'cursor-not-allowed border-transparent bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Phone className="size-5 fill-current" />
                                    </button>
                                ) : callState === 'ringing' ? (
                                    <div className="flex gap-10">
                                        <button
                                            type="button"
                                            aria-label={t('Decline')}
                                            title={t('Decline')}
                                            onClick={() =>
                                                globalSip.hangupCall()
                                            }
                                            className="flex size-13 items-center justify-center rounded-full border border-rose-400 bg-rose-500 text-white shadow-lg hover:bg-rose-400 active:scale-95"
                                        >
                                            <PhoneOff className="size-5" />
                                        </button>
                                        <button
                                            type="button"
                                            aria-label={t('Answer')}
                                            title={t('Answer')}
                                            onClick={() =>
                                                globalSip.answerCall()
                                            }
                                            className="flex size-13 animate-pulse items-center justify-center rounded-full border border-emerald-400 bg-emerald-500 text-white shadow-lg hover:bg-emerald-400 active:scale-95"
                                        >
                                            <Phone className="size-5 fill-current" />
                                        </button>
                                    </div>
                                ) : (
                                    <div className="flex gap-10">
                                        <button
                                            type="button"
                                            aria-label={
                                                isMuted
                                                    ? t('Unmute')
                                                    : t('Mute')
                                            }
                                            title={
                                                isMuted
                                                    ? t('Unmute')
                                                    : t('Mute')
                                            }
                                            onClick={() =>
                                                globalSip.toggleMute()
                                            }
                                            disabled={callState !== 'active'}
                                            className={cn(
                                                'flex size-13 items-center justify-center rounded-full border shadow-md active:scale-95 disabled:opacity-40',
                                                isMuted
                                                    ? 'border-rose-300 bg-rose-100 text-rose-500 dark:border-rose-900/60 dark:bg-rose-950/40'
                                                    : 'bg-background text-muted-foreground hover:bg-muted',
                                            )}
                                        >
                                            {isMuted ? (
                                                <MicOff className="size-5" />
                                            ) : (
                                                <Mic className="size-5" />
                                            )}
                                        </button>
                                        <button
                                            type="button"
                                            aria-label={t('Hang up')}
                                            title={t('Hang up')}
                                            onClick={() =>
                                                globalSip.hangupCall()
                                            }
                                            className="flex size-13 items-center justify-center rounded-full border border-rose-400 bg-rose-500 text-white shadow-lg hover:bg-rose-400 active:scale-95"
                                        >
                                            <PhoneOff className="size-5" />
                                        </button>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            )}
        </>
    );
}
