import { useForm } from '@inertiajs/react';
import { AlertTriangle, History } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { client as lookupClient } from '@/routes/operator/lookup';
import type { Branch, ClientCard, TariffClass } from '@/types/operator';
import { PlacePicker } from './place-picker';
import type { PickedPlace } from './place-picker';
import type { CallCard } from './softphone';

const emptyPlace: PickedPlace = {
    text: '',
    lat: null,
    lng: null,
    landmarkId: null,
};

export type PickTarget = 'pickup' | 'dropoff' | null;

type Props = {
    branches: Branch[];
    tariffClasses: TariffClass[];
    picking: PickTarget;
    setPicking: (target: PickTarget) => void;
    /** Xaritada bosilgan nuqta — tanlash rejimidagi maydonga yoziladi */
    mapClick: { lat: number; lng: number; seq: number } | null;
    onPlacesChange: (pickup: PickedPlace, dropoff: PickedPlace) => void;
    /** Kiruvchi qo'ng'iroq kartochkasi — telefon va filial avtomatik to'ldiriladi */
    incoming?: CallCard | null;
};

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
}: Props) {
    const { t } = useTrans();
    const phoneRef = useRef<HTMLInputElement>(null);
    const [card, setCard] = useState<ClientCard | null>(null);
    const [pickup, setPickup] = useState<PickedPlace>(emptyPlace);
    const [dropoff, setDropoff] = useState<PickedPlace>(emptyPlace);

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

    // Xarita bosilganda tanlangan maydonga nuqta
    useEffect(() => {
        if (!mapClick || !picking) {
            return;
        }
        const setter = picking === 'pickup' ? setPickup : setDropoff;
        setter((place) => ({
            ...place,
            lat: mapClick.lat,
            lng: mapClick.lng,
            landmarkId: null,
        }));
        setPicking(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mapClick?.seq]);

    useEffect(
        () => onPlacesChange(pickup, dropoff),
        [pickup, dropoff, onPlacesChange],
    );

    // F2 — yangi buyurtma: telefon maydoniga o'tish
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'F2') {
                e.preventDefault();
                phoneRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const lookup = (phone: string = form.data.phone) => {
        if (phone.replace(/\D/g, '').length < 9) {
            setCard(null);

            return;
        }
        getJson<ClientCard>(lookupClient.url({ query: { phone } }))
            .then((result) => {
                setCard(result);
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
        phoneRef.current?.focus();
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
            delivery: selectedClass?.is_delivery ? data.delivery : null,
        }));
        form.post(OrderController.store.url(), {
            preserveScroll: true,
            onSuccess: reset,
        });
    };

    const errors = form.errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="grid gap-3">
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

            <div className="grid grid-cols-2 gap-2">
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

            {card && card.addresses.length > 0 && (
                <div className="grid gap-1">
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <History className="size-3" />
                        {t('Recent places')} ({card.client?.orders_count ?? 0}{' '}
                        {t('orders')})
                    </span>
                    <div className="flex flex-wrap gap-1.5">
                        {card.addresses.map((address) => (
                            <Button
                                key={address.id}
                                type="button"
                                size="sm"
                                variant="secondary"
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
                <div className="grid grid-cols-2 gap-2 rounded-md border p-2">
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
            {pickup.lat === null && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Choose a landmark from the list or press the pin button and click on the map to set the pickup point.',
                    )}
                </p>
            )}
            {form.data.scheduled_at && (
                <p className="text-xs text-amber-600">
                    {t(
                        'Pre-order: the order goes on air shortly before the chosen time.',
                    )}
                </p>
            )}
        </form>
    );
}
