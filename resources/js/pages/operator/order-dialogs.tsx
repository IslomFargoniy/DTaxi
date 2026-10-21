import { router } from '@inertiajs/react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Operator/OrderController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { formatNumber } from '@/lib/format';
import type { Order, TariffClass } from '@/types/operator';
import { PlacePicker } from './place-picker';
import type { PickedPlace } from './place-picker';

type Who = 'client' | 'operator' | 'no_driver';

/** Tayyor sabablar: hisobotda bir xil yozilsin (erkin matn ham mumkin) */
const REASONS: { who: Who; text: string }[] = [
    { who: 'client', text: 'Client changed their mind' },
    { who: 'client', text: 'Driver is too far' },
    { who: 'client', text: 'Waited too long' },
    { who: 'client', text: 'Found another taxi' },
    { who: 'operator', text: 'Duplicate order' },
    { who: 'operator', text: 'Wrong address' },
    { who: 'no_driver', text: 'No free drivers' },
];

export function CancelOrderDialog({
    order,
    open,
    onOpenChange,
}: {
    order: Order;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTrans();
    const [choice, setChoice] = useState<{ who: Who; text: string } | null>(
        null,
    );
    const [other, setOther] = useState('');
    const [otherWho, setOtherWho] = useState<Who>('client');
    const [processing, setProcessing] = useState(false);

    const reason = choice ? t(choice.text) : other.trim();
    const who = choice?.who ?? otherWho;

    const submit = () => {
        if (!reason || processing) {
            return;
        }
        setProcessing(true);
        router.post(
            OrderController.cancel.url(order.id),
            {
                reason,
                by_client: who === 'client',
                no_driver_found: who === 'no_driver',
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent onClick={(e) => e.stopPropagation()}>
                <DialogHeader>
                    <DialogTitle>
                        {t('Cancel order')} #{order.id}
                    </DialogTitle>
                </DialogHeader>
                <div className="grid gap-3">
                    {(['client', 'operator', 'no_driver'] as const).map(
                        (group) => (
                            <div key={group} className="grid gap-1.5">
                                <span className="text-xs text-muted-foreground">
                                    {t(`cancel.${group}`)}
                                </span>
                                <div className="flex flex-wrap gap-1.5">
                                    {REASONS.filter((r) => r.who === group).map(
                                        (r) => (
                                            <Button
                                                key={r.text}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    choice?.text === r.text
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() => setChoice(r)}
                                            >
                                                {t(r.text)}
                                            </Button>
                                        ),
                                    )}
                                </div>
                            </div>
                        ),
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor={`cancel-other-${order.id}`}>
                            {t('Other reason')}
                        </Label>
                        <div className="flex gap-1.5">
                            <Input
                                id={`cancel-other-${order.id}`}
                                value={other}
                                onFocus={() => setChoice(null)}
                                onChange={(e) => {
                                    setChoice(null);
                                    setOther(e.target.value);
                                }}
                            />
                            <select
                                className="rounded-md border bg-background px-2 text-sm"
                                value={otherWho}
                                onChange={(e) =>
                                    setOtherWho(e.target.value as Who)
                                }
                            >
                                <option value="client">
                                    {t('cancel.client')}
                                </option>
                                <option value="operator">
                                    {t('cancel.operator')}
                                </option>
                                <option value="no_driver">
                                    {t('cancel.no_driver')}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('Back')}
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={!reason || processing}
                        onClick={submit}
                    >
                        {t('Cancel order')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const BONUSES = [2000, 5000, 10000];

export function EditOrderDialog({
    order,
    tariffClasses,
    open,
    onOpenChange,
}: {
    order: Order;
    tariffClasses: TariffClass[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTrans();
    const waiting = order.status === 'new' || order.status === 'searching';
    const [comment, setComment] = useState(order.comment ?? '');
    const [dropoff, setDropoff] = useState<PickedPlace>({
        text: order.dropoff?.text ?? '',
        lat: order.dropoff?.lat ?? null,
        lng: order.dropoff?.lng ?? null,
        landmarkId: null,
    });
    const [tariffClassId, setTariffClassId] = useState(
        order.tariff_class?.id ?? 0,
    );
    const [bonus, setBonus] = useState(order.price_bonus ?? 0);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const classes = tariffClasses.filter(
        (c) => c.branch_id === order.branch_id,
    );

    const submit = () => {
        setProcessing(true);
        router.patch(
            OrderController.update.url(order.id),
            {
                comment: comment || null,
                dropoff_text: dropoff.text || null,
                dropoff_lat: dropoff.text ? dropoff.lat : null,
                dropoff_lng: dropoff.text ? dropoff.lng : null,
                price_bonus: bonus || null,
                ...(waiting ? { tariff_class_id: tariffClassId } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent onClick={(e) => e.stopPropagation()}>
                <DialogHeader>
                    <DialogTitle>
                        {t('Edit order')} #{order.id}
                    </DialogTitle>
                </DialogHeader>
                <div className="grid gap-3">
                    <PlacePicker
                        id={`edit-dropoff-${order.id}`}
                        label="Destination (optional)"
                        value={dropoff}
                        onChange={setDropoff}
                        picking={false}
                        onPickOnMap={() => undefined}
                        near={
                            order.pickup.lat !== null &&
                            order.pickup.lng !== null
                                ? {
                                      lat: order.pickup.lat,
                                      lng: order.pickup.lng,
                                  }
                                : null
                        }
                    />
                    {waiting && classes.length > 1 && (
                        <div className="flex flex-wrap gap-1.5">
                            {classes.map((c) => (
                                <Button
                                    key={c.id}
                                    type="button"
                                    size="sm"
                                    variant={
                                        tariffClassId === c.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setTariffClassId(c.id)}
                                >
                                    {c.name}
                                </Button>
                            ))}
                        </div>
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor={`edit-comment-${order.id}`}>
                            {t('Comment for the driver')}
                        </Label>
                        <Input
                            id={`edit-comment-${order.id}`}
                            maxLength={500}
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                        />
                        <InputError message={errors.comment} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`edit-bonus-${order.id}`}>
                            {t('Extra to the price (so‘m)')}
                        </Label>
                        <div className="flex flex-wrap gap-1.5">
                            {BONUSES.map((amount) => (
                                <Button
                                    key={amount}
                                    type="button"
                                    size="sm"
                                    variant={
                                        bonus === amount ? 'default' : 'outline'
                                    }
                                    onClick={() =>
                                        setBonus(bonus === amount ? 0 : amount)
                                    }
                                >
                                    +{formatNumber(amount)}
                                </Button>
                            ))}
                            <div className="w-32">
                                <MoneyInput
                                    id={`edit-bonus-${order.id}`}
                                    currency={false}
                                    max={1000000}
                                    value={bonus || null}
                                    onChange={(v) => setBonus(v ?? 0)}
                                />
                            </div>
                        </div>
                        <InputError message={errors.price_bonus} />
                        {waiting && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'With an extra the order goes on air again for all drivers.',
                                )}
                            </p>
                        )}
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('Back')}
                    </Button>
                    <Button disabled={processing} onClick={submit}>
                        {t('Save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
