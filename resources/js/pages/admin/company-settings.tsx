import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import CompanySettingsController from '@/actions/App/Http/Controllers/Admin/CompanySettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/money-input';
import { formatNumber } from '@/lib/format';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { LOCALES } from '@/lib/locales';
import { useTrans } from '@/hooks/use-trans';
import { edit } from '@/routes/admin/company-settings';

type Settings = Record<string, string | number | string[]>;

// Chegaralar CompanySettingsController bilan bir xil
const MAX_QUICK_REPLIES = 20;
const MAX_QUICK_REPLY_LENGTH = 200;

/** Chatdagi tezkor javoblar ro'yxati: qo'shish, o'chirish, tartibini o'zgartirish */
function QuickRepliesEditor({
    value,
    onChange,
    errors,
}: {
    value: string[];
    onChange: (next: string[]) => void;
    errors: Record<string, string>;
}) {
    const { t } = useTrans();
    const move = (from: number, to: number) => {
        const next = [...value];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    return (
        <div className="grid gap-2">
            <p className="text-xs text-muted-foreground">
                {t(
                    'Buttons above the message box in the operator chat with drivers.',
                )}
            </p>
            {value.map((reply, i) => (
                <div key={i} className="grid gap-1">
                    <div className="flex gap-1">
                        <Input
                            aria-label={`${t('Quick reply')} ${i + 1}`}
                            maxLength={MAX_QUICK_REPLY_LENGTH}
                            value={reply}
                            onChange={(e) =>
                                onChange(
                                    value.map((r, j) =>
                                        j === i ? e.target.value : r,
                                    ),
                                )
                            }
                        />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            title={t('Move up')}
                            aria-label={t('Move up')}
                            disabled={i === 0}
                            onClick={() => move(i, i - 1)}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            title={t('Move down')}
                            aria-label={t('Move down')}
                            disabled={i === value.length - 1}
                            onClick={() => move(i, i + 1)}
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            title={t('Delete')}
                            aria-label={t('Delete')}
                            onClick={() =>
                                onChange(value.filter((_, j) => j !== i))
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    <InputError message={errors[`chat.quick_replies.${i}`]} />
                </div>
            ))}
            <InputError message={errors['chat.quick_replies']} />
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={value.length >= MAX_QUICK_REPLIES}
                    onClick={() => onChange([...value, ''])}
                >
                    <Plus /> {t('Add reply')}
                </Button>
            </div>
        </div>
    );
}

// Nuqtali kalitlar formada `__` bilan yuboriladi (company.name → company__name)
const toField = (key: string) => key.replaceAll('.', '__');

// Chegaralar serverdagi CompanySettingsController qoidalari bilan bir xil
const numericFields: {
    key: string;
    label: string;
    min: number;
    max?: number;
    step?: number;
}[] = [
    {
        key: 'drivers.commission_percent',
        label: 'Driver commission, %',
        min: 0,
        max: 100,
        step: 0.1,
    },
    {
        key: 'calls.recordings_retention_days',
        label: 'Call recordings retention, days',
        min: 1,
        max: 3650,
    },
];

const ROUNDING_STEPS = [1, 100, 500, 1000];

export default function CompanySettings({ settings }: { settings: Settings }) {
    const { t } = useTrans();
    const { data, setData, put, processing, errors, transform } = useForm<
        Record<string, string>
    >(
        Object.fromEntries(
            Object.entries(settings)
                .filter(([, v]) => !Array.isArray(v))
                .map(([k, v]) => [toField(k), String(v)]),
        ),
    );
    // Standart javoblar inglizcha kalit — tahrirlashda firma tilidagi matn ko'rinadi (va shunday saqlanadi)
    const [quickReplies, setQuickReplies] = useState<string[]>(() => {
        const list = settings['chat.quick_replies'];

        return Array.isArray(list) ? list.map((reply) => t(reply)) : [];
    });
    transform((form) => ({ ...form, chat__quick_replies: quickReplies }));
    const fieldErrors = errors as Record<string, string>;
    const numberField = ({
        key,
        label,
        min,
        max,
        step,
    }: (typeof numericFields)[number]) => (
        <div key={key} className="grid gap-2">
            <Label htmlFor={key}>{t(label)}</Label>
            <Input
                id={key}
                type="number"
                inputMode={step ? 'decimal' : 'numeric'}
                min={min}
                max={max}
                step={step ?? 1}
                value={data[toField(key)]}
                onChange={(e) => setData(toField(key), e.target.value)}
            />
            <InputError message={fieldErrors[key]} />
        </div>
    );

    return (
        <>
            <Head title={t('Company settings')} />
            <div className="max-w-4xl p-4">
                <Heading
                    title="Company settings"
                    description="Values used by every branch unless the branch overrides them."
                />
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(CompanySettingsController.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Main')}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid items-start gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="company-name">
                                    {t('Company name')}
                                </Label>
                                <Input
                                    id="company-name"
                                    maxLength={100}
                                    autoComplete="organization"
                                    value={data.company__name}
                                    onChange={(e) =>
                                        setData('company__name', e.target.value)
                                    }
                                />
                                <InputError
                                    message={fieldErrors['company.name']}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label>{t('Default language')}</Label>
                                <Select
                                    value={data.company__default_locale}
                                    onValueChange={(v) =>
                                        setData('company__default_locale', v)
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {LOCALES.map((l) => (
                                            <SelectItem
                                                key={l.code}
                                                value={l.code}
                                            >
                                                {l.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {numericFields
                                .filter(
                                    (field) =>
                                        field.key ===
                                        'calls.recordings_retention_days',
                                )
                                .map(numberField)}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Balance and commission')}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid items-start gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="drivers.min_balance">
                                    {t("Minimum driver balance, so'm")}
                                </Label>
                                <MoneyInput
                                    id="drivers.min_balance"
                                    currency={false}
                                    value={data.drivers__min_balance}
                                    onChange={(v) =>
                                        setData(
                                            'drivers__min_balance',
                                            v === null ? '' : String(v),
                                        )
                                    }
                                />
                                <InputError
                                    message={fieldErrors['drivers.min_balance']}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tariffs.rounding_step">
                                    {t("Price rounding step, so'm")}
                                </Label>
                                <select
                                    id="tariffs.rounding_step"
                                    className="h-9 w-full rounded-md border bg-transparent px-2 text-sm"
                                    value={data.tariffs__rounding_step}
                                    onChange={(e) =>
                                        setData(
                                            'tariffs__rounding_step',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {ROUNDING_STEPS.map((step) => (
                                        <option key={step} value={step}>
                                            {formatNumber(step)}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={
                                        fieldErrors['tariffs.rounding_step']
                                    }
                                />
                            </div>

                            {numericFields
                                .filter(
                                    (field) =>
                                        field.key !==
                                        'calls.recordings_retention_days',
                                )
                                .map(numberField)}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Chat quick replies')}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <QuickRepliesEditor
                                value={quickReplies}
                                onChange={setQuickReplies}
                                errors={fieldErrors}
                            />
                        </CardContent>
                    </Card>

                    <div>
                        <Button disabled={processing}>{t('Save')}</Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CompanySettings.layout = {
    breadcrumbs: [{ title: 'Company settings', href: edit() }],
};
