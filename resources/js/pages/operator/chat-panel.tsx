import { useEcho } from '@laravel/echo-react';
import { MessageSquare, Search, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    created_at: string | null;
};

const CHAT_WIDTH = 680;

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
                'flex shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white',
                size,
                AVATAR_COLORS[thread.driver_id % AVATAR_COLORS.length],
            )}
        >
            {(thread.callsign ?? thread.name ?? '?').slice(0, 3)}
        </span>
    );
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

const QUICK_REPLIES = [
    'Client is coming out',
    'Please wait 2 minutes',
    'Client cancelled',
    'Call the client',
];

function BranchChat({
    branchId,
    onMessage,
}: {
    branchId: number;
    onMessage: (m: Message) => void;
}) {
    useEcho<Message>(`branch.${branchId}`, 'ChatMessageSent', onMessage, [
        onMessage,
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
    const [open, setOpen] = useState(false);
    const [threads, setThreads] = useState<Thread[]>([]);
    const [active, setActive] = useState<number | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [text, setText] = useState('');
    const [query, setQuery] = useState('');
    const bottom = useRef<HTMLDivElement>(null);
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

    const clamp = (x: number, y: number) => ({
        x: Math.min(Math.max(8, x), window.innerWidth - CHAT_WIDTH - 8),
        y: Math.min(Math.max(8, y), window.innerHeight - 120),
    });

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
                clamp(e.clientX - drag.current.dx, e.clientY - drag.current.dy),
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

    const loadThreads = useCallback(() => {
        getJson<Thread[]>(threadsRoute.url())
            .then(setThreads)
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        loadThreads();
    }, [loadThreads]);

    // Suhbatni ochish xabarlarni o'qilgan qiladi — hisoblagich darhol yangilansin
    const loadMessages = useCallback(
        (driverId: number) => {
            getJson<Message[]>(messagesRoute.url(driverId))
                .then((list) => {
                    setMessages(list);
                    loadThreads();
                })
                .catch(() => setMessages([]));
        },
        [loadThreads],
    );

    useEffect(() => {
        if (active && open) {
            loadMessages(active);
        }
    }, [active, open, loadMessages]);

    // Suhbat tanlanmagan — o'ng tomon bo'sh (Telegram kabi), eski suhbat xabarlari ko'rinmasin
    useEffect(() => {
        if (active === null) {
            setMessages([]);
        }
    }, [active]);

    // Effekt hech narsa qaytarmasin: yangi Chrome'da scrollIntoView() Promise qaytaradi va React uni
    // tozalash funksiyasi deb chaqirib sahifani yiqitardi
    useEffect(() => {
        bottom.current?.scrollIntoView();
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

    const send = (body: string) => {
        if (!active || !body.trim()) {
            return;
        }
        postJson<Message>(sendRoute.url(active), { body })
            .then((m) =>
                setMessages((list) =>
                    list.some((x) => x.id === m.id) ? list : [...list, m],
                ),
            )
            .catch(() => undefined);
        setText('');
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
                <BranchChat key={id} branchId={id} onMessage={onMessage} />
            ))}
            {/* Suzuvchi tugma — o'ng pastda, har sahifada (layout'da turadi, sahifa almashsa ham yo'qolmaydi) */}
            <Button
                size="icon"
                title={t('Drivers chat')}
                className={cn(
                    'fixed right-6 bottom-6 z-50 size-14 rounded-full border-2 border-background shadow-xl',
                    unread > 0 && 'bg-red-600 text-white hover:bg-red-700',
                )}
                onClick={() => setOpen(!open)}
            >
                <MessageSquare className="size-6" />
                {unread > 0 && (
                    <span className="absolute -top-1 -right-1 flex size-6 items-center justify-center rounded-full bg-background text-xs font-bold text-red-600 ring-2 ring-red-600">
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
                            onClick={() => setOpen(false)}
                        >
                            <X />
                        </Button>
                    </div>
                    <div className="grid min-h-0 flex-1 grid-cols-[250px_1fr]">
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
                            <ul className="min-h-0 flex-1 overflow-auto px-1.5 pb-2">
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
                                    </span>
                                </div>
                                <div className="flex-1 space-y-1.5 overflow-auto bg-muted/40 px-3 py-3">
                                    {messages.map((m) => (
                                        <div
                                            key={m.id}
                                            className={cn(
                                                'flex',
                                                m.mine
                                                    ? 'justify-end'
                                                    : 'justify-start',
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
                                                    <span className="float-right mt-1.5 ml-2 text-[10px] leading-none opacity-60">
                                                        {new Date(
                                                            m.created_at,
                                                        ).toLocaleTimeString(
                                                            [],
                                                            {
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    <div ref={bottom} />
                                </div>
                                <div className="grid gap-2 border-t p-2">
                                    <div className="flex gap-1 overflow-x-auto">
                                        {QUICK_REPLIES.map((reply) => (
                                            <Button
                                                key={reply}
                                                size="sm"
                                                variant="outline"
                                                className="h-7 shrink-0 rounded-full text-xs"
                                                onClick={() => send(t(reply))}
                                            >
                                                {t(reply)}
                                            </Button>
                                        ))}
                                    </div>
                                    <form
                                        className="flex gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            send(text);
                                        }}
                                    >
                                        <Input
                                            value={text}
                                            onChange={(e) =>
                                                setText(e.target.value)
                                            }
                                            placeholder={t('Message')}
                                            className="rounded-full"
                                        />
                                        <Button
                                            size="icon"
                                            className="shrink-0 rounded-full"
                                            disabled={!text.trim()}
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
