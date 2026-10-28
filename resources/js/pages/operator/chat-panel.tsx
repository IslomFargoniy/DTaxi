import { usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    Check,
    CheckCheck,
    MessageSquare,
    Search,
    Send,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useResyncOnReconnect } from '@/hooks/use-resync-on-reconnect';
import { useTrans } from '@/hooks/use-trans';
import { getJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    messages as messagesRoute,
    send as sendRoute,
    threads as threadsRoute,
} from '@/routes/operator/chat';

type Thread = {
    driver_id: number;
    callsign: string | null;
    name: string | null;
    last_at: string;
    unread: number;
    last_body: string | null;
    last_photo: boolean;
    last_from_driver: boolean;
    /** Telegram kabi: ilova ochiq (oxirgi 90 s ichida so'rov) */
    online: boolean;
    last_active_at: string | null;
};
type Message = {
    id: number;
    driver_id: number;
    sender_id: number;
    sender_name: string | null;
    from_driver: boolean;
    mine: boolean;
    body: string | null;
    attachment_url: string | null;
    read_at: string | null;
    created_at: string | null;
};
type ReadEvent = { driver_id: number; by_driver: boolean; up_to_id: number };

const CHAT_WIDTH = 680;
const BUTTON_SIZE = 56;

type Point = { x: number; y: number };

/** Oyna ekran ichida qolsin; ekran tor bo'lsa — chap/yuqori chetga yopishadi */
function fit(point: Point, width: number, height: number): Point {
    const w = Math.min(width, window.innerWidth - 16);
    const next = {
        x: Math.max(8, Math.min(point.x, window.innerWidth - w - 8)),
        y: Math.max(8, Math.min(point.y, window.innerHeight - height - 8)),
    };

    return next.x === point.x && next.y === point.y ? point : next;
}

const fitChat = (p: Point) => fit(p, CHAT_WIDTH, 112);
const fitButton = (p: Point) => fit(p, BUTTON_SIZE, BUTTON_SIZE);
/** Serverdagi sahifa hajmi (ChatController::PAGE) — shuncha kelsa, yana bor */
const PAGE = 30;

/** Xabarlarni birlashtirish: takrorlarsiz, eskidan yangiga */
function mergeMessages(list: Message[], incoming: Message[]): Message[] {
    const byId = new Map(list.map((m) => [m.id, m]));
    incoming.forEach((m) => byId.set(m.id, m));

    return [...byId.values()].sort((a, b) => a.id - b.id);
}

/** Avatar rangi pozivnoy bo'yicha doim bir xil (Telegram kabi) */
const AVATAR_COLORS = [
    'bg-rose-500',
    'bg-orange-500',
    'bg-amber-500',
    'bg-emerald-500',
    'bg-teal-500',
    'bg-sky-500',
    'bg-indigo-500',
    'bg-fuchsia-500',
];

function Avatar({
    thread,
    size = 'size-10',
}: {
    thread: Thread;
    size?: string;
}) {
    return (
        <span
            className={cn(
                'relative flex shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white',
                size,
                AVATAR_COLORS[thread.driver_id % AVATAR_COLORS.length],
            )}
        >
            {(thread.callsign ?? thread.name ?? '?').slice(0, 3)}
            {thread.online && (
                <span className="absolute right-0 bottom-0 size-3 rounded-full border-2 border-background bg-emerald-500" />
            )}
        </span>
    );
}

/** Sarlavhadagi holat (Telegram kabi): online yoki oxirgi faollik */
function presenceText(
    thread: Thread,
    t: (key: string, replace?: Record<string, string>) => string,
): string {
    if (thread.online) {
        return t('online');
    }
    if (!thread.last_active_at) {
        return '';
    }
    const date = new Date(thread.last_active_at);
    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === new Date().toDateString()) {
        return t('last seen at :time', { time });
    }
    if (date.toDateString() === yesterday.toDateString()) {
        return t('last seen yesterday at :time', { time });
    }

    return t('last seen :date', {
        date: date.toLocaleDateString([], { day: '2-digit', month: '2-digit' }),
    });
}

/** Ro'yxatdagi vaqt: bugun — soat, aks holda sana */
function shortTime(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);

    return date.toDateString() === new Date().toDateString()
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
}

function BranchChat({
    branchId,
    onMessage,
    onRead,
}: {
    branchId: number;
    onMessage: (m: Message) => void;
    onRead: (e: ReadEvent) => void;
}) {
    useEcho<Message>(`branch.${branchId}`, 'ChatMessageSent', onMessage, [
        onMessage,
    ]);
    useEcho<ReadEvent>(`branch.${branchId}`, 'ChatMessagesRead', onRead, [
        onRead,
    ]);

    return null;
}

/**
 * Haydovchilar bilan chat (qaror #47): suhbatlar ro'yxati, tezkor javoblar, rasm.
 */
export function ChatPanel({
    branchIds,
    userId,
}: {
    branchIds: number[];
    userId: number;
}) {
    const { t } = useTrans();
    // Tezkor javoblar — firma sozlamasi (chat.quick_replies); standart matnlar t() bilan tarjima bo'ladi
    const quickReplies = (
        usePage<{ chatQuickReplies?: string[] }>().props.chatQuickReplies ?? []
    ).map((reply) => t(reply));
    const [open, setOpen] = useState(false);
    const [threads, setThreads] = useState<Thread[]>([]);
    const [active, setActive] = useState<number | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    // Qoralama har haydovchiga alohida — boshqa suhbatga o'tganda matn u yerga ko'chmaydi
    const [drafts, setDrafts] = useState<Record<number, string>>({});
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState(false);
    const [query, setQuery] = useState('');
    // Ochiq suhbat — kechikkan javob boshqa suhbatga yozilmasligi uchun
    const activeRef = useRef<number | null>(null);
    useEffect(() => {
        activeRef.current = active;
        setSendError(false);
    }, [active]);
    // Oyna joyi (sudrab ko'chiriladi, brauzerda eslab qolinadi); null — o'ng pastda
    const [position, setPosition] = useState<{ x: number; y: number } | null>(
        () => {
            try {
                const saved = localStorage.getItem('chat-position');

                return saved ? JSON.parse(saved) : null;
            } catch {
                return null;
            }
        },
    );
    const drag = useRef<{ dx: number; dy: number } | null>(null);

    // Tugmani ham sudrash mumkin (ba'zi sahifalarda orqasidagi ma'lumotni yopadi); joyi eslab qolinadi
    const [buttonPos, setButtonPos] = useState<{ x: number; y: number } | null>(
        () => {
            try {
                const saved = localStorage.getItem('chat-button-position');

                return saved ? JSON.parse(saved) : null;
            } catch {
                return null;
            }
        },
    );
    const buttonDrag = useRef<{
        dx: number;
        dy: number;
        startX: number;
        startY: number;
        moved: boolean;
    } | null>(null);

    const onButtonDown = (e: React.PointerEvent<HTMLButtonElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();
        buttonDrag.current = {
            dx: e.clientX - rect.left,
            dy: e.clientY - rect.top,
            startX: e.clientX,
            startY: e.clientY,
            moved: false,
        };
        e.currentTarget.setPointerCapture(e.pointerId);
    };
    const onButtonMove = (e: React.PointerEvent<HTMLButtonElement>) => {
        const d = buttonDrag.current;
        if (!d) {
            return;
        }
        // 5 px dan kam siljish — oddiy bosish (chatni ochadi)
        if (
            !d.moved &&
            Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < 5
        ) {
            return;
        }
        d.moved = true;
        setButtonPos(fitButton({ x: e.clientX - d.dx, y: e.clientY - d.dy }));
    };
    const onButtonUp = () => {
        const d = buttonDrag.current;
        buttonDrag.current = null;
        if (d?.moved) {
            try {
                localStorage.setItem(
                    'chat-button-position',
                    JSON.stringify(buttonPos),
                );
            } catch {
                // Saqlab bo'lmasa — keyingi safar o'ng pastda
            }

            return;
        }
        setOpen((value) => !value);
    };

    // Saqlangan joy kichikroq ekranda yoki oyna o'lchami o'zgarganda ekrandan chiqib qolmasin
    useEffect(() => {
        const refit = () => {
            setPosition((p) => (p ? fitChat(p) : p));
            setButtonPos((p) => (p ? fitButton(p) : p));
        };
        refit();
        window.addEventListener('resize', refit);

        return () => window.removeEventListener('resize', refit);
    }, []);

    const startDrag = (e: React.PointerEvent<HTMLDivElement>) => {
        if ((e.target as HTMLElement).closest('button')) {
            return;
        }
        const rect = e.currentTarget.parentElement?.getBoundingClientRect();
        if (!rect) {
            return;
        }
        drag.current = { dx: e.clientX - rect.left, dy: e.clientY - rect.top };
        e.currentTarget.setPointerCapture(e.pointerId);
    };
    const moveDrag = (e: React.PointerEvent<HTMLDivElement>) => {
        if (drag.current) {
            setPosition(
                fitChat({
                    x: e.clientX - drag.current.dx,
                    y: e.clientY - drag.current.dy,
                }),
            );
        }
    };
    const endDrag = () => {
        drag.current = null;
        try {
            localStorage.setItem('chat-position', JSON.stringify(position));
        } catch {
            // Saqlab bo'lmasa — keyingi safar o'ng pastda ochiladi
        }
    };

    const scroller = useRef<HTMLDivElement>(null);
    // Eskilari yuqoridan qo'shilganda ko'rinish sakramasin; yangi xabarda — pastga
    const restore = useRef<{ height: number; top: number } | null>(null);
    const stickBottom = useRef(true);
    const [hasOlder, setHasOlder] = useState(false);
    const loadingOlder = useRef(false);
    const [hasMoreThreads, setHasMoreThreads] = useState(false);
    const loadingThreads = useRef(false);

    // Ketma-ket so'rovlardan faqat oxirgisining javobi olinadi — eski javob o'qilmaganlar sonini qaytarmasin
    const threadsSeq = useRef(0);
    const threadsTimer = useRef<number | undefined>(undefined);

    // Birinchi sahifa yangilanadi, pastga aylantirib yuklanganlari saqlanadi
    const fetchThreads = useCallback(() => {
        const seq = ++threadsSeq.current;
        getJson<Thread[]>(threadsRoute.url())
            .then((page) => {
                if (seq !== threadsSeq.current) {
                    return;
                }
                setThreads((prev) => {
                    const ids = new Set(page.map((th) => th.driver_id));
                    const rest = prev
                        .slice(PAGE)
                        .filter((th) => !ids.has(th.driver_id));

                    return [...page, ...rest];
                });
                setHasMoreThreads((more) => more || page.length === PAGE);
            })
            .catch(() => undefined);
    }, []);

    // Xabarlar ketma-ket kelsa har biriga so'rov ketmasin — 300 ms ichidagilar bitta so'rovga birlashadi
    const loadThreads = useCallback(() => {
        window.clearTimeout(threadsTimer.current);
        threadsTimer.current = window.setTimeout(fetchThreads, 300);
    }, [fetchThreads]);

    useEffect(() => () => window.clearTimeout(threadsTimer.current), []);

    const loadMoreThreads = () => {
        if (!hasMoreThreads || loadingThreads.current) {
            return;
        }
        loadingThreads.current = true;
        getJson<Thread[]>(
            threadsRoute.url({ query: { offset: threads.length } }),
        )
            .then((page) => {
                setThreads((prev) => [
                    ...prev,
                    ...page.filter(
                        (th) => !prev.some((p) => p.driver_id === th.driver_id),
                    ),
                ]);
                setHasMoreThreads(page.length === PAGE);
            })
            .catch(() => undefined)
            .finally(() => {
                loadingThreads.current = false;
            });
    };

    useEffect(() => {
        fetchThreads();
    }, [fetchThreads]);

    // Chat ochiq — haydovchilarning online holati yangilanib tursin
    useEffect(() => {
        if (!open) {
            return;
        }
        const id = window.setInterval(fetchThreads, 30_000);

        return () => window.clearInterval(id);
    }, [open, fetchThreads]);

    // Suhbatni ochish xabarlarni o'qilgan qiladi — hisoblagich darhol yangilansin.
    // initial — yangi suhbat: ro'yxat almashadi; aks holda — yuklanganlarga qo'shiladi
    const loadMessages = useCallback(
        (driverId: number, initial = false) => {
            getJson<Message[]>(messagesRoute.url(driverId))
                .then((list) => {
                    // Operator bu orada boshqa suhbatga o'tgan — eski javob tashlanadi
                    if (activeRef.current !== driverId) {
                        return;
                    }
                    if (initial) {
                        stickBottom.current = true;
                        setMessages(list);
                        setHasOlder(list.length === PAGE);
                    } else {
                        setMessages((prev) => mergeMessages(prev, list));
                    }
                    loadThreads();
                })
                .catch(
                    () =>
                        initial &&
                        activeRef.current === driverId &&
                        setMessages([]),
                );
        },
        [loadThreads],
    );

    const loadOlder = () => {
        const first = messages[0];
        if (!active || !first || !hasOlder || loadingOlder.current) {
            return;
        }
        loadingOlder.current = true;
        const driverId = active;
        getJson<Message[]>(
            messagesRoute.url(driverId, { query: { before_id: first.id } }),
        )
            .then((list) => {
                if (activeRef.current !== driverId) {
                    return;
                }
                const el = scroller.current;
                restore.current = el
                    ? { height: el.scrollHeight, top: el.scrollTop }
                    : null;
                setMessages((prev) => mergeMessages(prev, list));
                setHasOlder(list.length === PAGE);
            })
            .catch(() => undefined)
            .finally(() => {
                loadingOlder.current = false;
            });
    };

    useEffect(() => {
        if (active && open) {
            loadMessages(active, true);
        }
    }, [active, open, loadMessages]);

    // Suhbat tanlanmagan — o'ng tomon bo'sh (Telegram kabi), eski suhbat xabarlari ko'rinmasin
    useEffect(() => {
        if (active === null) {
            setMessages([]);
            setHasOlder(false);
        }
    }, [active]);

    useLayoutEffect(() => {
        const el = scroller.current;
        if (!el) {
            return;
        }
        if (restore.current) {
            el.scrollTop =
                el.scrollHeight - restore.current.height + restore.current.top;
            restore.current = null;
        } else if (stickBottom.current) {
            el.scrollTop = el.scrollHeight;
        }
    }, [messages]);

    const onMessage = useCallback(
        (m: Message) => {
            if (m.driver_id === active && open) {
                setMessages((list) =>
                    list.some((x) => x.id === m.id)
                        ? list
                        : [...list, { ...m, mine: m.sender_id === userId }],
                );
                // Ochiq suhbatga kelgan xabar — operator ko'rib turibdi, o'qilgan deb belgilanadi
                if (m.from_driver) {
                    loadMessages(active);

                    return;
                }
            }
            loadThreads();
        },
        [active, open, userId, loadThreads, loadMessages],
    );

    // Haydovchi dispetcher xabarlarini o'qidi — ochiq suhbatda ✓ → ✓✓
    const onRead = useCallback((e: ReadEvent) => {
        if (!e.by_driver || e.driver_id !== activeRef.current) {
            return;
        }
        const at = new Date().toISOString();
        setMessages((list) =>
            list.map((m) =>
                !m.from_driver && !m.read_at && m.id <= e.up_to_id
                    ? { ...m, read_at: at }
                    : m,
            ),
        );
    }, []);

    // Uzilish paytida kelgan xabarlar Reverb'dan qayta kelmaydi — suhbatlar va ochiq suhbat qayta olinadi
    useResyncOnReconnect(
        useCallback(() => {
            fetchThreads();
            if (open && activeRef.current !== null) {
                loadMessages(activeRef.current);
            }
        }, [open, fetchThreads, loadMessages]),
    );

    const text = active !== null ? (drafts[active] ?? '') : '';
    const setDraft = (driverId: number, update: (current: string) => string) =>
        setDrafts((all) => ({
            ...all,
            [driverId]: update(all[driverId] ?? ''),
        }));

    // Matn faqat yuborilgach tozalanadi; xatoda maydonda qoladi va qayta yuborish mumkin
    const send = (body: string) => {
        if (!active || !body.trim() || sending) {
            return;
        }
        const driverId = active;
        stickBottom.current = true;
        setSending(true);
        setSendError(false);
        postJson<Message>(sendRoute.url(driverId), { body })
            .then((m) => {
                if (activeRef.current === driverId) {
                    setMessages((list) =>
                        list.some((x) => x.id === m.id) ? list : [...list, m],
                    );
                }
                setDraft(driverId, (current) =>
                    current === body ? '' : current,
                );
            })
            .catch(() => {
                if (activeRef.current === driverId) {
                    setSendError(true);
                    // Tezkor javob ham yo'qolmasin — maydonga qo'yiladi
                    setDraft(driverId, (current) =>
                        current.trim() ? current : body,
                    );
                }
            })
            .finally(() => setSending(false));
    };

    const unread = threads.reduce((sum, th) => sum + th.unread, 0);
    const activeThread = threads.find((th) => th.driver_id === active);
    const needle = query.trim().toLowerCase();
    const visibleThreads = needle
        ? threads.filter((th) =>
              `${th.callsign ?? ''} ${th.name ?? ''}`
                  .toLowerCase()
                  .includes(needle),
          )
        : threads;

    return (
        <>
            {branchIds.map((id) => (
                <BranchChat
                    key={id}
                    branchId={id}
                    onMessage={onMessage}
                    onRead={onRead}
                />
            ))}
            {/* Suzuvchi tugma — standart o'ng pastda, sudrab ko'chiriladi; har sahifada (layout'da turadi) */}
            <Button
                size="icon"
                title={t('Drivers chat')}
                aria-label={t('Drivers chat')}
                className={cn(
                    'fixed z-50 size-14 cursor-grab touch-none rounded-full border-2 border-background shadow-xl active:cursor-grabbing',
                    unread > 0 && 'bg-red-600 text-white hover:bg-red-700',
                )}
                style={
                    buttonPos
                        ? { left: buttonPos.x, top: buttonPos.y }
                        : { right: 24, bottom: 24 }
                }
                onPointerDown={onButtonDown}
                onPointerMove={onButtonMove}
                onPointerUp={onButtonUp}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setOpen((value) => !value);
                    }
                }}
            >
                <MessageSquare className="size-6" />
                {unread > 0 && (
                    <span className="absolute -top-1 -right-1 flex size-6 items-center justify-center rounded-full bg-white text-xs font-bold text-red-600 shadow-sm ring-2 ring-red-600">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </Button>
            {open && (
                <div
                    className="fixed z-50 flex h-[560px] max-h-[calc(100svh-2rem)] w-[680px] max-w-[calc(100vw-1rem)] flex-col overflow-hidden rounded-xl border-2 border-primary/40 bg-card text-card-foreground shadow-2xl ring-1 ring-black/10 dark:bg-zinc-900"
                    style={
                        position
                            ? { left: position.x, top: position.y }
                            : { right: 24, bottom: 96 }
                    }
                >
                    {/* Sarlavha — ushlab sudraladi */}
                    <div
                        className="flex cursor-move items-center gap-2 border-b bg-muted px-3 py-2 select-none"
                        onPointerDown={startDrag}
                        onPointerMove={moveDrag}
                        onPointerUp={endDrag}
                    >
                        <MessageSquare className="size-4 text-primary" />
                        <span className="text-sm font-semibold">
                            {t('Drivers chat')}
                        </span>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="ml-auto size-7"
                            aria-label={t('Close')}
                            onClick={() => setOpen(false)}
                        >
                            <X />
                        </Button>
                    </div>
                    <div className="grid min-h-0 flex-1 grid-cols-[250px_minmax(0,1fr)]">
                        {/* Suhbatlar ro'yxati */}
                        <div className="flex min-h-0 flex-col border-r">
                            <div className="p-2">
                                <div className="relative">
                                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        placeholder={t('Search')}
                                        className="h-9 rounded-full bg-muted pl-9"
                                    />
                                </div>
                            </div>
                            <ul
                                className="min-h-0 flex-1 overflow-auto px-1.5 pb-2"
                                onScroll={(e) => {
                                    const el = e.currentTarget;
                                    if (
                                        el.scrollHeight -
                                            el.scrollTop -
                                            el.clientHeight <
                                        80
                                    ) {
                                        loadMoreThreads();
                                    }
                                }}
                            >
                                {visibleThreads.map((th) => {
                                    const selected = active === th.driver_id;
                                    const preview =
                                        th.last_photo && !th.last_body
                                            ? `📷 ${t('Photo')}`
                                            : (th.last_body ?? '');

                                    return (
                                        <li key={th.driver_id}>
                                            <button
                                                type="button"
                                                className={cn(
                                                    'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left',
                                                    selected
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'hover:bg-muted',
                                                )}
                                                onClick={() =>
                                                    setActive(
                                                        selected
                                                            ? null
                                                            : th.driver_id,
                                                    )
                                                }
                                            >
                                                <Avatar thread={th} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-baseline gap-2">
                                                        <span className="truncate text-sm font-semibold">
                                                            {th.callsign} ·{' '}
                                                            {th.name}
                                                        </span>
                                                        <span
                                                            className={cn(
                                                                'ml-auto shrink-0 text-[11px]',
                                                                selected
                                                                    ? 'text-primary-foreground/70'
                                                                    : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {shortTime(
                                                                th.last_at,
                                                            )}
                                                        </span>
                                                    </span>
                                                    <span className="flex items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'truncate text-xs',
                                                                selected
                                                                    ? 'text-primary-foreground/80'
                                                                    : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {!th.last_from_driver &&
                                                                preview && (
                                                                    <span
                                                                        className={cn(
                                                                            !selected &&
                                                                                'text-primary',
                                                                        )}
                                                                    >
                                                                        {t(
                                                                            'Operator',
                                                                        )}
                                                                        :{' '}
                                                                    </span>
                                                                )}
                                                            {preview}
                                                        </span>
                                                        {th.unread > 0 && (
                                                            <span className="ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-bold text-white">
                                                                {th.unread}
                                                            </span>
                                                        )}
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                                {visibleThreads.length === 0 && (
                                    <li className="p-3 text-center text-xs text-muted-foreground">
                                        {t('No messages yet')}
                                    </li>
                                )}
                            </ul>
                        </div>

                        {/* Suhbat */}
                        {!activeThread ? (
                            <div className="flex items-center justify-center bg-muted/40">
                                <span className="rounded-full bg-background/80 px-3 py-1 text-xs text-muted-foreground shadow-sm">
                                    {t('Select a chat to start messaging')}
                                </span>
                            </div>
                        ) : (
                            <div className="flex min-h-0 flex-col">
                                <div className="flex items-center gap-2.5 border-b px-3 py-2">
                                    <Avatar
                                        thread={activeThread}
                                        size="size-8"
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-semibold">
                                            {activeThread.callsign} ·{' '}
                                            {activeThread.name}
                                        </span>
                                        <span
                                            className={cn(
                                                'block truncate text-xs',
                                                activeThread.online
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {presenceText(activeThread, t)}
                                        </span>
                                    </span>
                                </div>
                                <div
                                    ref={scroller}
                                    className="flex-1 space-y-1.5 overflow-auto bg-muted/40 px-3 py-3"
                                    onScroll={(e) => {
                                        const el = e.currentTarget;
                                        // Pastda turgan bo'lsa — yangi xabar kelganda pastga ergashadi
                                        stickBottom.current =
                                            el.scrollHeight -
                                                el.scrollTop -
                                                el.clientHeight <
                                            120;
                                        if (el.scrollTop < 80) {
                                            loadOlder();
                                        }
                                    }}
                                >
                                    {hasOlder && (
                                        <div className="py-1 text-center text-[11px] text-muted-foreground">
                                            {t('Loading…')}
                                        </div>
                                    )}
                                    {messages.map((m) => (
                                        <div
                                            key={m.id}
                                            className={cn(
                                                'flex',
                                                // Dispetcherlik tomoni (men va boshqa operatorlar) — o'ngda, haydovchi — chapda
                                                m.from_driver
                                                    ? 'justify-start'
                                                    : 'justify-end',
                                            )}
                                        >
                                            <div
                                                className={cn(
                                                    'max-w-[78%] rounded-2xl px-3 py-1.5 text-sm shadow-sm',
                                                    m.mine
                                                        ? 'rounded-br-sm bg-primary text-primary-foreground'
                                                        : m.from_driver
                                                          ? 'rounded-bl-sm bg-background'
                                                          : 'rounded-br-sm bg-sky-100 text-sky-950 dark:bg-sky-900 dark:text-sky-50',
                                                )}
                                            >
                                                {/* Bir haydovchi bilan bir nechta operator yozishishi mumkin — boshqa operator nomi */}
                                                {!m.mine && !m.from_driver && (
                                                    <div className="text-[11px] font-semibold opacity-80">
                                                        {m.sender_name}
                                                    </div>
                                                )}
                                                {m.attachment_url && (
                                                    <a
                                                        href={m.attachment_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <img
                                                            src={
                                                                m.attachment_url
                                                            }
                                                            alt=""
                                                            className="my-1 max-h-48 rounded-lg"
                                                        />
                                                    </a>
                                                )}
                                                <span className="break-words whitespace-pre-wrap">
                                                    {m.body}
                                                </span>
                                                {m.created_at && (
                                                    <span className="float-right mt-1.5 ml-2 inline-flex items-center gap-0.5 text-[10px] leading-none opacity-60">
                                                        {new Date(
                                                            m.created_at,
                                                        ).toLocaleTimeString(
                                                            [],
                                                            {
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            },
                                                        )}
                                                        {/* Telegram kabi: ✓ yuborildi, ✓✓ haydovchi o'qidi */}
                                                        {!m.from_driver &&
                                                            (m.read_at ? (
                                                                <CheckCheck
                                                                    className="size-3.5"
                                                                    aria-label={t(
                                                                        'Read',
                                                                    )}
                                                                />
                                                            ) : (
                                                                <Check
                                                                    className="size-3.5"
                                                                    aria-label={t(
                                                                        'Sent',
                                                                    )}
                                                                />
                                                            ))}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="grid gap-2 border-t p-2">
                                    <div className="flex gap-1 overflow-x-auto empty:hidden">
                                        {quickReplies.map((reply, i) => (
                                            <Button
                                                key={i}
                                                size="sm"
                                                variant="outline"
                                                className="h-7 shrink-0 rounded-full text-xs"
                                                disabled={sending}
                                                onClick={() => send(reply)}
                                            >
                                                {reply}
                                            </Button>
                                        ))}
                                    </div>
                                    {sendError && (
                                        <div
                                            role="alert"
                                            className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
                                        >
                                            <span className="flex-1">
                                                {t(
                                                    'Message not sent. Check the connection and try again.',
                                                )}
                                            </span>
                                            <button
                                                type="button"
                                                className="font-semibold underline-offset-2 hover:underline"
                                                onClick={() => send(text)}
                                            >
                                                {t('Retry')}
                                            </button>
                                        </div>
                                    )}
                                    <form
                                        className="flex gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            send(text);
                                        }}
                                    >
                                        <Input
                                            value={text}
                                            onChange={(e) => {
                                                if (active !== null) {
                                                    const value =
                                                        e.target.value;
                                                    setDraft(
                                                        active,
                                                        () => value,
                                                    );
                                                }
                                                setSendError(false);
                                            }}
                                            placeholder={t('Message')}
                                            className="rounded-full"
                                        />
                                        <Button
                                            size="icon"
                                            className="shrink-0 rounded-full"
                                            disabled={!text.trim() || sending}
                                            aria-label={t('Send')}
                                        >
                                            <Send />
                                        </Button>
                                    </form>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
