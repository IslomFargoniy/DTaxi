import { Head } from '@inertiajs/react';
import { useTrans } from '@/hooks/use-trans';

const SECTIONS: { title: string; body: string }[] = [
    {
        title: 'What we collect',
        body: 'Your phone number and name, the pickup and destination addresses of your orders, and your location only while the app is open and you choose the pickup point. We do not collect location in the background.',
    },
    {
        title: 'Why',
        body: 'To accept your order, find the nearest car, show the driver your pickup point, calculate the price and keep the trip history. The driver sees your name, pickup point, destination and phone number only while your order is active, so that they can call you; after the trip your number is hidden from the driver.',
    },
    {
        title: 'Sign-in',
        body: 'We call your number and say a 4-digit code. Calls are made only when you request a code.',
    },
    {
        title: 'Storage',
        body: 'Data is stored on the taxi company server. Trip history is kept as accounting records of the company. Calls with the dispatcher and the driver (by phone or from the app) are recorded to resolve disputes about orders; the microphone is used only during a call you start. Recordings are deleted after the retention period set by the company.',
    },
    {
        title: 'Deleting your account',
        body: 'In the app: Settings → Delete account. Your sign-in, saved addresses and name entered in the app are deleted; trip history stays in the company records. You can also ask the dispatcher by phone.',
    },
];

/**
 * Mijoz ilovasining maxfiylik siyosati (qaror #71) — Google Play uchun ochiq sahifa.
 */
export default function Privacy({
    company,
    phone,
}: {
    company: string;
    phone: string | null;
}) {
    const { t } = useTrans();

    return (
        <>
            <Head title={t('Privacy policy')} />
            <div className="mx-auto grid max-w-2xl gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">{company}</h1>
                    <p className="text-muted-foreground">
                        {t('Privacy policy')}
                    </p>
                </div>
                {SECTIONS.map((s) => (
                    <section key={s.title} className="grid gap-1">
                        <h2 className="font-semibold">{t(s.title)}</h2>
                        <p className="text-sm leading-relaxed">{t(s.body)}</p>
                    </section>
                ))}
                {phone && (
                    <p className="text-sm">
                        {t('Contact')}: <a href={`tel:${phone}`}>{phone}</a>
                    </p>
                )}
            </div>
        </>
    );
}
