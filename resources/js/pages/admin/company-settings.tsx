import { Head, useForm } from '@inertiajs/react';
import CompanySettingsController from '@/actions/App/Http/Controllers/Admin/CompanySettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTrans } from '@/hooks/use-trans';
import { edit } from '@/routes/admin/company-settings';

type Settings = Record<string, string | number>;

// Nuqtali kalitlar formada `__` bilan yuboriladi (company.name → company__name)
const toField = (key: string) => key.replaceAll('.', '__');

const numericFields: { key: string; label: string }[] = [
    { key: 'drivers.commission_percent', label: 'Driver commission, %' },
    { key: 'drivers.min_balance', label: "Minimum driver balance, so'm" },
    { key: 'tariffs.rounding_step', label: "Price rounding step, so'm" },
    {
        key: 'calls.recordings_retention_days',
        label: 'Call recordings retention, days',
    },
];

export default function CompanySettings({ settings }: { settings: Settings }) {
    const { t } = useTrans();
    const { data, setData, put, processing, errors } = useForm<
        Record<string, string>
    >(
        Object.fromEntries(
            Object.entries(settings).map(([k, v]) => [toField(k), String(v)]),
        ),
    );
    const fieldErrors = errors as Record<string, string>;

    return (
        <>
            <Head title={t('Company settings')} />
            <div className="max-w-xl p-4">
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
                    <div className="grid gap-2">
                        <Label htmlFor="company-name">
                            {t('Company name')}
                        </Label>
                        <Input
                            id="company-name"
                            value={data.company__name}
                            onChange={(e) =>
                                setData('company__name', e.target.value)
                            }
                        />
                        <InputError message={fieldErrors['company.name']} />
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('Default language')}</Label>
                        <Select
                            value={data.company__default_locale}
                            onValueChange={(v) =>
                                setData('company__default_locale', v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="uz">{t('Uzbek')}</SelectItem>
                                <SelectItem value="ru">
                                    {t('Russian')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {numericFields.map(({ key, label }) => (
                        <div key={key} className="grid gap-2">
                            <Label htmlFor={key}>{t(label)}</Label>
                            <Input
                                id={key}
                                type="number"
                                min={0}
                                value={data[toField(key)]}
                                onChange={(e) =>
                                    setData(toField(key), e.target.value)
                                }
                            />
                            <InputError message={fieldErrors[key]} />
                        </div>
                    ))}

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
