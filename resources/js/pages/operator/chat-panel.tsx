import { useEcho } from '@laravel/echo-react';
import { MessageSquare, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
    const bottom = useRef<HTMLDivElement>(null);

    const loadThreads = useCallback(() => {
        getJson<Thread[]>(threadsRoute.url())
            .then(setThreads)
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        loadThreads();
    }, [loadThreads]);

    useEffect(() => {
        if (active) {
            getJson<Message[]>(messagesRoute.url(active))
                .then(setMessages)
                .catch(() => setMessages([]));
        }
    }, [active]);

    // Effekt hech narsa qaytarmasin: yangi Chrome'da scrollIntoView() Promise qaytaradi va React uni
    // tozalash funksiyasi deb chaqirib sahifani yiqitardi
    useEffect(() => {
        bottom.current?.scrollIntoView();
    }, [messages]);

    const onMessage = useCallback(
        (m: Message) => {
            if (m.driver_id === active) {
                setMessages((list) =>
                    list.some((x) => x.id === m.id)
                        ? list
                        : [...list, { ...m, mine: m.sender_id === userId }],
                );
            }
            loadThreads();
        },
        [active, userId, loadThreads],
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

    return (
        <>
            {branchIds.map((id) => (
                <BranchChat key={id} branchId={id} onMessage={onMessage} />
            ))}
            <Button
                variant="outline"
                size="sm"
                className="relative"
                onClick={() => setOpen(!open)}
            >
                <MessageSquare /> {t('Drivers chat')}
                {unread > 0 && (
                    <Badge className="ml-1 bg-red-600 text-white">
                        {unread}
                    </Badge>
                )}
            </Button>
            {open && (
                <div className="fixed right-4 bottom-4 z-40 grid h-[520px] w-[560px] max-w-[calc(100vw-2rem)] grid-cols-[180px_1fr] overflow-hidden rounded-xl border bg-background shadow-xl">
                    <ul className="overflow-auto border-r">
                        {threads.map((th) => (
                            <li key={th.driver_id}>
                                <button
                                    type="button"
                                    className={cn(
                                        'w-full px-3 py-2 text-left text-sm hover:bg-accent',
                                        active === th.driver_id && 'bg-accent',
                                    )}
                                    onClick={() => setActive(th.driver_id)}
                                >
                                    <span className="font-mono font-medium">
                                        {th.callsign}
                                    </span>
                                    {th.unread > 0 && (
                                        <Badge className="ml-1 bg-red-600 text-white">
                                            {th.unread}
                                        </Badge>
                                    )}
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {th.name}
                                    </span>
                                </button>
                            </li>
                        ))}
                        {threads.length === 0 && (
                            <li className="p-3 text-xs text-muted-foreground">
                                {t('No messages yet')}
                            </li>
                        )}
                    </ul>
                    <div className="flex min-h-0 flex-col">
                        <div className="flex items-center justify-end border-b p-1">
                            <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                            >
                                <X />
                            </Button>
                        </div>
                        <div className="flex-1 space-y-2 overflow-auto p-3">
                            {messages.map((m) => (
                                <div
                                    key={m.id}
                                    className={cn(
                                        'max-w-[80%] rounded-lg px-3 py-1.5 text-sm',
                                        m.mine
                                            ? 'ml-auto bg-primary text-primary-foreground'
                                            : m.from_driver
                                              ? 'bg-muted'
                                              : 'bg-blue-100 text-blue-950 dark:bg-blue-950 dark:text-blue-50',
                                    )}
                                >
                                    {/* Bir haydovchi bilan bir nechta operator yozishishi mumkin — kim yozgani */}
                                    <div className="mb-0.5 flex gap-2 text-[11px] opacity-70">
                                        <span className="font-medium">
                                            {m.mine
                                                ? t('You')
                                                : m.from_driver
                                                  ? `${activeThread?.callsign ?? ''} · ${m.sender_name ?? ''}`
                                                  : `${t('Operator')}: ${m.sender_name ?? ''}`}
                                        </span>
                                        {m.created_at && (
                                            <span className="ml-auto">
                                                {new Date(
                                                    m.created_at,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </span>
                                        )}
                                    </div>
                                    {m.body}
                                    {m.attachment_url && (
                                        <a
                                            href={m.attachment_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <img
                                                src={m.attachment_url}
                                                alt=""
                                                className="mt-1 max-h-40 rounded"
                                            />
                                        </a>
                                    )}
                                </div>
                            ))}
                            <div ref={bottom} />
                        </div>
                        {active && (
                            <div className="grid gap-2 border-t p-2">
                                <div className="flex flex-wrap gap-1">
                                    {QUICK_REPLIES.map((reply) => (
                                        <Button
                                            key={reply}
                                            size="sm"
                                            variant="secondary"
                                            className="h-7 text-xs"
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
                                    />
                                    <Button size="icon">
                                        <Send />
                                    </Button>
                                </form>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
