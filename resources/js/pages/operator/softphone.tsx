import { useEcho } from '@laravel/echo-react';
import { UA, WebSocketInterface } from 'jssip';
import type { RTCSession } from 'jssip/lib/RTCSession';
import type { IncomingRTCSessionEvent } from 'jssip/lib/UA';
import {
    Mic,
    MicOff,
    Phone,
    PhoneIncoming,
    PhoneMissed,
    PhoneOff,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { getJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    missed as missedRoute,
    show as showRoute,
    status as statusRoute,
} from '@/routes/operator/phone';

type Sip = { extension: string; uri: string; password: string; ws: string };
type OperatorStatus = 'offline' | 'ready' | 'paused';

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

type MissedCall = {
    id: number;
    phone: string;
    client: string | null;
    started_at: string;
};

type CallState = 'idle' | 'ringing' | 'dialing' | 'talking';

const MEDIA = { audio: true, video: false };

/**
 * Operator softfoni (§5.5): brauzerda WebRTC ichki raqam (JsSIP → Asterisk /ws).
 * Holat "Tayyor" bo'lsa navbatdagi qo'ng'iroqlar shu operatorga keladi; kartochka pop-up buyurtma formasini to'ldiradi.
 */
export function Softphone({
    userId,
    onCard,
}: {
    userId: number;
    onCard: (card: CallCard) => void;
}) {
    const { t } = useTrans();
    const ua = useRef<UA | null>(null);
    const audio = useRef<HTMLAudioElement | null>(null);
    const active = useRef<RTCSession | null>(null);
    const [sip, setSip] = useState<Sip | null>(null);
    const [registered, setRegistered] = useState(false);
    const [status, setStatus] = useState<OperatorStatus>('offline');
    const [session, setSession] = useState<RTCSession | null>(null);
    const [state, setState] = useState<CallState>('idle');
    const [card, setCard] = useState<CallCard | null>(null);
    const [muted, setMuted] = useState(false);
    const [dial, setDial] = useState('');
    const [missed, setMissed] = useState<MissedCall[]>([]);
    const [showMissed, setShowMissed] = useState(false);
    const [startedAt, setStartedAt] = useState<number | null>(null);
    const [, setNow] = useState(0);

    const loadMissed = useCallback(() => {
        getJson<MissedCall[]>(missedRoute.url())
            .then(setMissed)
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        getJson<{ sip: Sip | null; status: OperatorStatus }>(showRoute.url())
            .then((r) => {
                setSip(r.sip);
                setStatus(r.status);
            })
            .catch(() => undefined);
        loadMissed();
    }, [loadMissed]);

    // Suhbat davomiyligi
    useEffect(() => {
        if (!startedAt) {
            return;
        }
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, [startedAt]);

    const attach = useCallback((s: RTCSession) => {
        active.current = s;
        setSession(s);
        s.on('peerconnection', ({ peerconnection }) => {
            peerconnection.addEventListener('track', (e: RTCTrackEvent) => {
                if (audio.current) {
                    audio.current.srcObject = e.streams[0];
                }
            });
        });
        s.on('confirmed', () => {
            setState('talking');
            setStartedAt(Date.now());
        });
        const end = () => {
            active.current = null;
            setSession(null);
            setState('idle');
            setStartedAt(null);
            setMuted(false);
            setCard(null);
        };
        s.on('ended', end);
        s.on('failed', end);
    }, []);

    // Ro'yxatdan o'tish
    useEffect(() => {
        if (!sip) {
            return;
        }
        const agent = new UA({
            sockets: [new WebSocketInterface(sip.ws)],
            uri: sip.uri,
            password: sip.password,
            register: true,
            session_timers: false,
        });
        agent.on('registered', () => setRegistered(true));
        agent.on('unregistered', () => setRegistered(false));
        agent.on('registrationFailed', () => setRegistered(false));
        agent.on('disconnected', () => setRegistered(false));
        agent.on('newRTCSession', (e: IncomingRTCSessionEvent) => {
            if (e.originator !== 'remote') {
                return;
            }
            // Bir vaqtda bitta suhbat
            if (active.current) {
                e.session.terminate({ status_code: 486 });

                return;
            }
            setState('ringing');
            attach(e.session);
        });
        agent.start();
        ua.current = agent;

        return () => {
            agent.stop();
            ua.current = null;
        };
    }, [sip, attach]);

    // Server: qo'ng'iroq kartochkasi, holat o'zgarishi, javobsiz
    useEcho<{ type: string; status?: OperatorStatus } & Partial<CallCard>>(
        `user.${userId}`,
        'OperatorCallEvent',
        (e) => {
            if (e.type === 'incoming' && e.call_id) {
                const c = e as unknown as CallCard;
                setCard(c);
                onCard(c);
            } else if (e.type === 'status' && e.status) {
                setStatus(e.status);
            } else if (e.type === 'ended') {
                loadMissed();
            }
        },
        [onCard, loadMissed],
    );

    const changeStatus = (next: OperatorStatus) => {
        postJson<{ status: OperatorStatus }>(statusRoute.url(), {
            status: next,
        })
            .then((r) => setStatus(r.status))
            .catch(() => undefined);
    };

    const answer = () => session?.answer({ mediaConstraints: MEDIA });
    const hangup = () => session?.terminate();
    const toggleMute = () => {
        if (!session) {
            return;
        }
        if (muted) {
            session.unmute({ audio: true });
        } else {
            session.mute({ audio: true });
        }
        setMuted(!muted);
    };

    const call = (target: string) => {
        const digits = target.replace(/[^\dA-Za-z]/g, '');
        if (!ua.current || !sip || digits.length < 3) {
            return;
        }
        const host = sip.uri.split('@')[1];
        const s = ua.current.call(`sip:${digits}@${host}`, {
            mediaConstraints: MEDIA,
        });
        setState('dialing');
        attach(s);
        setShowMissed(false);
    };

    const seconds = startedAt ? Math.floor((Date.now() - startedAt) / 1000) : 0;
    const duration = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

    const who = card?.driver
        ? `${t('Driver')} #${card.driver.callsign} — ${card.driver.name}`
        : (card?.client?.name ??
          card?.phone ??
          session?.remote_identity.uri.user ??
          '');

    return (
        <div className="relative rounded-xl border p-2 text-sm">
            <audio ref={audio} autoPlay />

            <div className="flex items-center gap-2">
                <span
                    className={cn(
                        'size-2 rounded-full',
                        registered ? 'bg-green-500' : 'bg-gray-400',
                    )}
                    title={
                        registered
                            ? t('Connected')
                            : t('Phone is not connected')
                    }
                />
                <div className="flex gap-1">
                    {(['ready', 'paused', 'offline'] as const).map((s) => (
                        <Button
                            key={s}
                            size="sm"
                            variant={status === s ? 'default' : 'outline'}
                            disabled={s === 'ready' && !registered}
                            onClick={() => changeStatus(s)}
                            className="h-7 px-2 text-xs"
                        >
                            {t(
                                s === 'ready'
                                    ? 'Ready'
                                    : s === 'paused'
                                      ? 'Paused'
                                      : 'Offline',
                            )}
                        </Button>
                    ))}
                </div>
                <Button
                    size="sm"
                    variant="ghost"
                    className="relative ml-auto h-7 px-2"
                    onClick={() => setShowMissed(!showMissed)}
                    title={t('Missed calls')}
                >
                    <PhoneMissed className="size-4" />
                    {missed.length > 0 && (
                        <Badge
                            variant="destructive"
                            className="absolute -top-1 -right-1 h-4 min-w-4 px-1 text-[10px]"
                        >
                            {missed.length}
                        </Badge>
                    )}
                </Button>
            </div>

            {state === 'idle' ? (
                <form
                    className="mt-2 flex gap-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        call(dial);
                    }}
                >
                    <Input
                        value={dial}
                        onChange={(e) => setDial(e.target.value)}
                        placeholder={t('Number or extension')}
                        className="h-8"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        className="h-8"
                        disabled={!registered}
                    >
                        <Phone className="size-4" />
                    </Button>
                </form>
            ) : (
                <div
                    className={cn(
                        'mt-2 rounded-lg p-2',
                        state === 'ringing'
                            ? 'animate-pulse bg-green-50 dark:bg-green-950'
                            : 'bg-muted',
                    )}
                >
                    <div className="flex items-center gap-2">
                        <PhoneIncoming className="size-4 shrink-0" />
                        <div className="min-w-0 flex-1">
                            <div className="truncate font-medium">{who}</div>
                            <div className="text-xs text-muted-foreground">
                                {state === 'ringing' && t('Incoming call')}
                                {state === 'dialing' && t('Calling…')}
                                {state === 'talking' && duration}
                                {card?.client &&
                                    ` · ${t('Orders')}: ${card.client.orders_count}`}
                            </div>
                            {card?.client?.note && (
                                <div className="text-xs text-amber-600">
                                    {card.client.note}
                                </div>
                            )}
                        </div>
                        {state === 'ringing' && (
                            <Button
                                size="sm"
                                className="h-8 bg-green-600 hover:bg-green-700"
                                onClick={answer}
                            >
                                {t('Answer')}
                            </Button>
                        )}
                        {state === 'talking' && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8"
                                onClick={toggleMute}
                                title={muted ? t('Unmute') : t('Mute')}
                            >
                                {muted ? (
                                    <MicOff className="size-4" />
                                ) : (
                                    <Mic className="size-4" />
                                )}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="destructive"
                            className="h-8"
                            onClick={hangup}
                            title={t('Hang up')}
                        >
                            <PhoneOff className="size-4" />
                        </Button>
                    </div>
                </div>
            )}

            {showMissed && (
                <div className="absolute top-full right-0 z-20 mt-1 max-h-80 w-72 overflow-auto rounded-lg border bg-popover p-1 shadow-lg">
                    <div className="px-2 py-1 text-xs font-semibold">
                        {t('Missed calls')}
                    </div>
                    {missed.length === 0 && (
                        <div className="px-2 py-3 text-xs text-muted-foreground">
                            {t('No missed calls')}
                        </div>
                    )}
                    {missed.map((m) => (
                        <div
                            key={m.id}
                            className="flex items-center gap-2 rounded px-2 py-1 hover:bg-muted"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="truncate">
                                    {m.client ?? m.phone}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {m.client && `${m.phone} · `}
                                    {new Date(m.started_at).toLocaleTimeString(
                                        [],
                                        { hour: '2-digit', minute: '2-digit' },
                                    )}
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-7"
                                disabled={!registered || state !== 'idle'}
                                onClick={() => call(m.phone)}
                                title={t('Call back')}
                            >
                                <Phone className="size-3.5" />
                            </Button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
