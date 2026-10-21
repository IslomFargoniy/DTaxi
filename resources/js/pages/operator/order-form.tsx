import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    CarFront,
    History,
    Plus,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { getJson } from '@/lib/http';
import { orderStatusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import {
    client as lookupClient,
    reverse as lookupReverse,
} from '@/routes/operator/lookup';
import type { Branch, ClientCard, TariffClass } from '@/types/operator';
import { PlacePicker } from './place-picker';
import type { PickedPlace } from './place-picker';
import type { CallCard } from '@/components/webphone/webphone';

const emptyPlace: PickedPlace = {
    text: '',
    lat: null,
    lng: null,
    landmarkId: null,
};

export type PickTarget = 'pickup' | 'dropoff' | `stop-${number}` | null;

type Props = {
    branches: Branch[];
    tariffClasses: TariffClass[];
    picking: PickTarget;
    setPicking: (target: PickTarget) => void;
    /** Xaritada bosilgan nuqta — tanlash rejimidagi maydonga yoziladi */
    mapClick: {
        lat: number;
        lng: number;
        seq: number;
        target?: 'pickup' | 'dropoff';
    } | null;
    onPlacesChange: (pickup: PickedPlace, dropoff: PickedPlace) => void;
    /** Kiruvchi qo'ng'iroq kartochkasi — telefon va filial avtomatik to'ldiriladi */
    incoming?: CallCard | null;
    /** Mijozning faol buyurtmasini ro'yxatda ko'rsatish */
    onShowOrder?: (id: number) => void;
};

const ACTIVE_STATUSES = [
    'new',
    'searching',
    'assigned',
    'arrived',
    'in_progress',
];

/**
 * Yangi buyurtma formasi (reja §8.1). Doimiy mijoz uchun oxirgi manzil bir bosishda tanlanadi.
 */
export function OrderForm({
    branches,
    tariffClasses,
    picking,
    setPicking,
    mapClick,
    onPlacesChange,
    incoming,
    onShowOrder,
}: Props) {
    const { t } = useTrans();
    const phoneRef = useRef<HTMLInputElement>(null);
    const formRef = useRef<HTMLFormElement>(null);
    const [card, setCard] = useState<ClientCard | null>(null);
    const [pickup, setPickup] = useState<PickedPlace>(emptyPlace);
    const [dropoff, setDropoff] = useState<PickedPlace>(emptyPlace);
    const [stops, setStops] = useState<PickedPlace[]>([]);

    const form = useForm({
        branch_id: branches[0]?.id ?? 0,
        phone: '',
        call_id: null as number | null,
        client_name: '',
        tariff_class_id: 0,
        comment: '',
        scheduled_at: '',
        delivery: {
            sender_name: '',
            sender_phone: '',
            recipient_name: '',
            recipient_phone: '',
            description: '',
        },
    });

    const classes = tariffClasses.filter(
        (c) => c.branch_id === form.data.branch_id,
    );
    const selectedClass =
        classes.find((c) => c.id === form.data.tariff_class_id) ??
        classes.find((c) => c.is_default) ??
        classes[0];

    // Xarita bosilganda: 📍 bilan tanlangan maydon; aks holda avval "qayerdan", keyin "qayerga".
    // Belgilangan nuqta surilsa (target) — o'sha maydon yangilanadi
    useEffect(() => {
        const target =
            mapClick?.target ??
            picking ??
            (pickup.lat === null
                ? 'pickup'
                : dropoff.lat === null
                  ? 'dropoff'
                  : null);
        if (!mapClick || !target) {
            return;
        }
        const { lat, lng } = mapClick;
        // Operator yozgan matn saqlanadi; aks holda — yaqin manzil nomi ("Mustaqillik ko'chasi, 22")
        const keepsText = (prev: PickedPlace) =>
            prev.text.trim() !== '' && !prev.auto;
        const place = (prev: PickedPlace): PickedPlace => ({
            ...prev,
            text: keepsText(prev) ? prev.text : t('Point on the map'),
            auto: !keepsText(prev),
            lat,
            lng,
            landmarkId: null,
        });
        // Javob kelguncha nuqta yana surilgan bo'lsa — eski nom yozilmaydi
        const named =
            (name: string) =>
            (prev: PickedPlace): PickedPlace =>
                prev.auto && prev.lat === lat && prev.lng === lng
                    ? { ...prev, text: name }
                    : prev;
        const apply = (update: (prev: PickedPlace) => PickedPlace) => {
            if (target.startsWith('stop-')) {
                const index = Number(target.slice(5));
                setStops((list) =>
                    list.map((stop, i) => (i === index ? update(stop) : stop)),
                );
            } else {
                (target === 'pickup' ? setPickup : setDropoff)(update);
            }
        };
        apply(place);
        getJson<{ name: string | null }>(
            lookupReverse.url({ query: { lat, lng } }),
        )
            .then(({ name }) => name && apply(named(name)))
            .catch(() => undefined);
        setPicking(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mapClick?.seq]);

    useEffect(() => {
        onPlacesChange(pickup, dropoff);
    }, [pickup, dropoff, onPlacesChange]);

    // Klaviatura: F2 — telefon, Ctrl+Enter — yuborish, Alt+1..5 — oxirgi manzil, Esc — xaritadan tanlashni bekor qilish
    const addresses = card?.addresses;
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'F2') {
                e.preventDefault();
                phoneRef.current?.focus();
            } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                formRef.current?.requestSubmit();
            } else if (e.altKey && /^Digit[1-5]$/.test(e.code)) {
                const address = addresses?.[Number(e.code.slice(5)) - 1];
                if (address) {
                    e.preventDefault();
                    setPickup({
                        text: address.text,
                        lat: address.lat,
                        lng: address.lng,
                        landmarkId: address.landmark_id,
                    });
                }
            } else if (e.key === 'Escape') {
                setPicking(null);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [addresses, setPicking]);

    const lookup = (phone: string = form.data.phone) => {
        if (phone.replace(/\D/g, '').length < 9) {
            setCard(null);

            return;
        }
        getJson<ClientCard>(lookupClient.url({ query: { phone } }))
            .then((result) => {
                setCard(result);
                // Doimiy mijoz: oxirgi manzil darhol tanlanadi (operator o'zgartirishi mumkin)
                const last = result.addresses[0];
                if (last) {
                    setPickup((place) =>
                        place.lat === null && place.text === ''
                            ? {
                                  text: last.text,
                                  lat: last.lat,
                                  lng: last.lng,
                                  landmarkId: last.landmark_id,
                              }
                            : place,
                    );
                }
                if (result.client?.name) {
                    form.setData((data) =>
                        data.client_name
                            ? data
                            : { ...data, client_name: result.client!.name! },
                    );
                }
            })
            .catch(() => setCard(null));
    };

    // Kiruvchi qo'ng'iroq: forma mijoz raqami va filial bilan ochiladi
    useEffect(() => {
        if (!incoming || incoming.kind !== 'client' || !incoming.phone) {
            return;
        }
        const phone = incoming.phone;
        const branchId = branches.some((b) => b.id === incoming.branch_id)
            ? (incoming.branch_id as number)
            : form.data.branch_id;
        form.setData((data) => ({
            ...data,
            phone,
            client_name: incoming.client?.name ?? '',
            branch_id: branchId,
            call_id: incoming.call_id,
        }));
        setPickup(emptyPlace);
        setDropoff(emptyPlace);
        lookup(phone);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [incoming?.call_id]);

    const reset = () => {
        form.reset();
        setCard(null);
        setPickup(emptyPlace);
        setDropoff(emptyPlace);
        setStops([]);
        phoneRef.current?.focus();
    };

    // Borib-kelish: hozirgi "qayerga" oraliq to'xtashga o'tadi, oxirgi nuqta — qayerdan
    const roundTrip = () => {
        if (pickup.lat === null) {
            return;
        }
        if (dropoff.lat !== null) {
            setStops((list) => [...list, dropoff]);
        }
        setDropoff({ ...pickup, landmarkId: null });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            tariff_class_id: selectedClass?.id,
            pickup_text: pickup.text,
            pickup_lat: pickup.lat,
            pickup_lng: pickup.lng,
            pickup_landmark_id: pickup.landmarkId,
            dropoff_text: dropoff.text || null,
            dropoff_lat: dropoff.lat,
            dropoff_lng: dropoff.lng,
            stops: stops
                .filter((stop) => stop.lat !== null && stop.text.trim())
                .map(({ text, lat, lng }) => ({ text, lat, lng })),
            delivery: selectedClass?.is_delivery ? data.delivery : null,
        }));
        form.post(OrderController.store.url(), {
            preserveScroll: true,
            onSuccess: reset,
        });
    };

    const errors = form.errors as Record<string, string>;

    return (
        <form ref={formRef} onSubmit={submit} className="grid gap-3">
            {branches.length > 1 && (
                <div className="flex flex-wrap gap-1.5">
                    {branches.map((branch) => (
                        <Button
                            key={branch.id}
                            type="button"
                            size="sm"
                            variant={
                                form.data.branch_id === branch.id
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => form.setData('branch_id', branch.id)}
                        >
                            {branch.name}
                        </Button>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-2 items-start gap-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="phone">{t('Phone')}</Label>
                    <Input
                        id="phone"
                        ref={phoneRef}
                        autoFocus
                        inputMode="tel"
                        placeholder="90 123 45 67"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        onBlur={() => lookup()}
                    />
                    <InputError message={errors.phone} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="client_name">{t('Name')}</Label>
                    <Input
                        id="client_name"
                        value={form.data.client_name}
                        onChange={(e) =>
                            form.setData('client_name', e.target.value)
                        }
                    />
                </div>
            </div>

            {card?.client?.is_blacklisted && (
                <div className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    <AlertTriangle className="size-4" />
                    {t('Client is blacklisted')}
                    {card.client.note && (
                        <span className="text-xs">— {card.client.note}</span>
                    )}
                </div>
            )}

            {card?.orders
                .filter((o) => ACTIVE_STATUSES.includes(o.status))
                .map((o) => (
                    <div
                        key={o.id}
                        className="grid gap-1 rounded-md border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm"
                    >
                        <div className="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
                            <CarFront className="size-4" />
                            {t('Client already has an active order')} #{o.id} —{' '}
                            {t(orderStatusLabel[o.status])}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {o.pickup_text}
                            {o.dropoff_text && ` → ${o.dropoff_text}`}
                        </div>
                        {o.driver && (
                            <div className="text-xs">
                                🚕 {o.driver} · {o.vehicle}
                            </div>
                        )}
                        {onShowOrder && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="justify-self-start"
                                onClick={() => onShowOrder(o.id)}
                            >
                                {t('Show order')}
                            </Button>
                        )}
                    </div>
                ))}

            {card && card.addresses.length > 0 && (
                <div className="grid gap-1">
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <History className="size-3" />
                        {t('Recent places')} ({card.client?.orders_count ?? 0}{' '}
                        {t('orders')})
                    </span>
                    <div className="flex flex-wrap gap-1.5">
                        {card.addresses.map((address, index) => (
                            <Button
                                key={address.id}
                                type="button"
                                size="sm"
                                variant="secondary"
                                title={`Alt+${index + 1}`}
                                onClick={() =>
                                    setPickup({
                                        text: address.text,
                                        lat: address.lat,
                                        lng: address.lng,
                                        landmarkId: address.landmark_id,
                                    })
                                }
                            >
                                {address.label ?? address.text}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            <PlacePicker
                id="pickup"
                label="Pickup"
                value={pickup}
                onChange={setPickup}
                near={
                    branches.find((b) => b.id === form.data.branch_id)?.center
                }
                picking={picking === 'pickup'}
                onPickOnMap={() =>
                    setPicking(picking === 'pickup' ? null : 'pickup')
                }
            />
            <InputError message={errors.pickup_text ?? errors.pickup_lat} />

            {stops.map((stop, index) => (
                <div key={index} className="flex items-end gap-1.5">
                    <div className="flex-1">
                        <PlacePicker
                            id={`stop-${index}`}
                            label={`${t('Stop')} ${index + 1}`}
                            value={stop}
                            onChange={(value) =>
                                setStops((list) =>
                                    list.map((s, i) =>
                                        i === index ? value : s,
                                    ),
                                )
                            }
                            near={
                                branches.find(
                                    (b) => b.id === form.data.branch_id,
                                )?.center
                            }
                            picking={picking === `stop-${index}`}
                            onPickOnMap={() =>
                                setPicking(
                                    picking === `stop-${index}`
                                        ? null
                                        : `stop-${index}`,
                                )
                            }
                        />
                    </div>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        title={t('Remove stop')}
                        onClick={() =>
                            setStops((list) =>
                                list.filter((_, i) => i !== index),
                            )
                        }
                    >
                        <X />
                    </Button>
                </div>
            ))}

            <div className="-mt-1 flex flex-wrap gap-1.5">
                {stops.length < 5 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 text-xs"
                        onClick={() =>
                            setStops((list) => [...list, emptyPlace])
                        }
                    >
                        <Plus className="size-3" /> {t('Add stop')}
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-7 text-xs"
                    disabled={pickup.lat === null}
                    onClick={roundTrip}
                >
                    <ArrowLeftRight className="size-3" /> {t('Round trip')}
                </Button>
            </div>

            <PlacePicker
                id="dropoff"
                label="Destination (optional)"
                value={dropoff}
                onChange={setDropoff}
                near={
                    branches.find((b) => b.id === form.data.branch_id)?.center
                }
                picking={picking === 'dropoff'}
                onPickOnMap={() =>
                    setPicking(picking === 'dropoff' ? null : 'dropoff')
                }
            />

            {card && card.dropoffs.length > 0 && (
                <div className="-mt-1 flex flex-wrap gap-1.5">
                    {card.dropoffs.map((place) => (
                        <Button
                            key={place.text}
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-7 text-xs"
                            onClick={() =>
                                setDropoff({
                                    text: place.text,
                                    lat: place.lat,
                                    lng: place.lng,
                                    landmarkId: null,
                                })
                            }
                        >
                            → {place.text}
                        </Button>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap gap-1.5">
                {classes.map((tariffClass) => (
                    <Button
                        key={tariffClass.id}
                        type="button"
                        size="sm"
                        variant={
                            selectedClass?.id === tariffClass.id
                                ? 'default'
                                : 'outline'
                        }
                        onClick={() =>
                            form.setData('tariff_class_id', tariffClass.id)
                        }
                    >
                        {tariffClass.name}
                    </Button>
                ))}
            </div>

            {selectedClass?.is_delivery && (
                <div className="grid grid-cols-2 items-start gap-2 rounded-md border p-2">
                    {(
                        [
                            'sender_name',
                            'sender_phone',
                            'recipient_name',
                            'recipient_phone',
                        ] as const
                    ).map((field) => (
                        <Input
                            key={field}
                            placeholder={t(field)}
                            value={form.data.delivery[field]}
                            onChange={(e) =>
                                form.setData('delivery', {
                                    ...form.data.delivery,
                                    [field]: e.target.value,
                                })
                            }
                        />
                    ))}
                    <Input
                        className="col-span-2"
                        placeholder={t('What to deliver')}
                        value={form.data.delivery.description}
                        onChange={(e) =>
                            form.setData('delivery', {
                                ...form.data.delivery,
                                description: e.target.value,
                            })
                        }
                    />
                </div>
            )}

            <Input
                placeholder={t('Comment for the driver')}
                value={form.data.comment}
                onChange={(e) => form.setData('comment', e.target.value)}
            />

            {/* Oldindan buyurtma: bo'sh — hozir */}
            <div className="grid gap-1">
                <Label
                    htmlFor="scheduled_at"
                    className="text-xs font-normal text-muted-foreground"
                >
                    {t('Pre-order time (empty — now)')}
                </Label>
                <Input
                    id="scheduled_at"
                    type="datetime-local"
                    value={form.data.scheduled_at}
                    onChange={(e) =>
                        form.setData('scheduled_at', e.target.value)
                    }
                />
                <InputError message={errors.scheduled_at} />
            </div>

            <div className="flex gap-2">
                <Button
                    className={cn('flex-1')}
                    disabled={form.processing || pickup.lat === null}
                >
                    {t('Create order')}
                </Button>
                <Button type="button" variant="outline" onClick={reset}>
                    {t('Clear')}
                </Button>
            </div>
            {/* Tugma nega o'chiqligini ko'rsatish: olib ketish nuqtasi xaritada belgilanmagan */}
            {pickup.lat !== null && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Drag a marker on the map to adjust it, or press the pin button and click the map again.',
                    )}
                </p>
            )}
            {pickup.lat === null && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Choose a landmark from the list or press the pin button and click on the map to set the pickup point.',
                    )}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {t('Keys')}: F2 — {t('phone')}, Alt+1…5 — {t('recent place')},
                Ctrl+Enter — {t('create')}, Esc — {t('stop picking')}
            </p>
            {form.data.scheduled_at && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                    {t(
                        'Pre-order: the order goes on air shortly before the chosen time.',
                    )}
                </p>
            )}
        </form>
    );
}
