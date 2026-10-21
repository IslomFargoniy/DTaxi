import { useConnectionStatus } from '@laravel/echo-react';
import { useSip } from '@/components/webphone/use-sip';
import { useCan } from '@/hooks/use-can';
import { useTrans } from '@/hooks/use-trans';
import { useGpsAge } from '@/lib/gps-health';
import { cn } from '@/lib/utils';

type Level = 'ok' | 'warn' | 'bad' | 'idle';

const DOT: Record<Level, string> = {
    ok: 'bg-emerald-500',
    warn: 'bg-amber-500',
    bad: 'bg-red-500 animate-pulse',
    idle: 'bg-sky-500',
};

function Item({
    level,
    label,
    title,
}: {
    level: Level;
    label: string;
    title: string;
}) {
    return (
        <span className="flex items-center gap-1.5" title={title}>
            <span className={cn('size-2 shrink-0 rounded-full', DOT[level])} />
            <span className="hidden sm:inline">{label}</span>
        </span>
    );
}

/** GPS hodisasi shuncha soniyadan beri kelmasa — ogohlantirish / xato */
const GPS_WARN_SECONDS = 60;
const GPS_BAD_SECONDS = 180;

/**
 * Sarlavhadagi ulanish holati: telefon (SIP), jonli yangilanishlar (Reverb) va haydovchi GPS oqimi.
 * Operator nima uchun qo'ng'iroq yoki buyurtma kelmayotganini darhol ko'radi.
 */
export function ConnectionHealth() {
    const { t } = useTrans();
    const { can } = useCan();
    const sip = useSip();
    const realtime = useConnectionStatus();
    const gpsAge = useGpsAge();
    const canPhone = can('calls.answer');

    const sipLevel: Level =
        sip.tabRole === 'follower'
            ? 'idle'
            : sip.status === 'connected'
              ? 'ok'
              : sip.status === 'connecting' || sip.tabRole === 'pending'
                ? 'warn'
                : 'bad';
    const sipTitle =
        sip.tabRole === 'follower'
            ? t('Phone is open in another tab')
            : {
                  connected: t('Phone connected'),
                  connecting: t('Connecting…'),
                  disconnected: t('Disconnected — reconnecting…'),
                  registration_failed: t('Connection error — retrying…'),
              }[sip.status];

    const realtimeLevel: Level =
        realtime === 'connected'
            ? 'ok'
            : realtime === 'connecting' || realtime === 'reconnecting'
              ? 'warn'
              : 'bad';

    const gpsLevel: Level =
        gpsAge === null
            ? 'ok'
            : gpsAge <= GPS_WARN_SECONDS
              ? 'ok'
              : gpsAge <= GPS_BAD_SECONDS
                ? 'warn'
                : 'bad';

    return (
        <div
            className="flex items-center gap-3 rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground"
            aria-label={t('Connection status')}
        >
            {canPhone && (
                <Item level={sipLevel} label={t('Phone')} title={sipTitle} />
            )}
            <Item
                level={realtimeLevel}
                label={t('Live')}
                title={
                    realtimeLevel === 'ok'
                        ? t('Live updates connected')
                        : t('Live updates disconnected — orders may be stale')
                }
            />
            {gpsAge !== null && (
                <Item
                    level={gpsLevel}
                    label="GPS"
                    title={t('Last driver location :seconds s ago', {
                        seconds: String(gpsAge),
                    })}
                />
            )}
        </div>
    );
}
