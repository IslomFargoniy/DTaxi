import { Head, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/hooks/use-trans';
import { store } from '@/routes/setup';

type Region = { id: number; name: string; parent: string | null };

/**
 * Birinchi kirish ustasi: firma, til, superadmin, birinchi filial (Bosqich 3b).
 */
export default function Setup({ regions }: { regions: Region[] }) {
    const { t } = useTrans();
    const form = useForm({
        company_name: '',
        locale: 'uz',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        branch_name: '',
        region_id: '' as number | '',
    });
    const field = (
        key: 'company_name' | 'name' | 'email' | 'branch_name',
        label: string,
        type = 'text',
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{t(label)}</Label>
            <Input
                id={key}
                type={type}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
                required
            />
            <InputError message={form.errors[key]} />
        </div>
    );
    const groups = regions.reduce<Record<string, Region[]>>((acc, r) => {
        (acc[r.parent ?? ''] ??= []).push(r);

        return acc;
    }, {});

    return (
        <>
            <Head title={t('Setup')} />
            <form
                className="grid gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(store.url());
                }}
            >
                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-sm font-semibold">
                        {t('Company')}
                    </legend>
                    {field('company_name', 'Service name (e.g. Ibrat taksi)')}
                    <div className="grid gap-2">
                        <Label>{t('Default language')}</Label>
                        <select
                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={form.data.locale}
                            onChange={(e) =>
                                form.setData('locale', e.target.value)
                            }
                        >
                            <option value="uz">O‘zbekcha</option>
                            <option value="ru">Русский</option>
                        </select>
                    </div>
                </fieldset>

                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-sm font-semibold">
                        {t('Superadmin')}
                    </legend>
                    {field('name', 'Full name')}
                    {field('email', 'Email address', 'email')}
                    <div className="grid gap-2">
                        <Label htmlFor="password">{t('Password')}</Label>
                        <PasswordInput
                            id="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            {t('Confirm password')}
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                            required
                        />
                    </div>
                </fieldset>

                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-sm font-semibold">
                        {t('First branch')}
                    </legend>
                    {field('branch_name', 'Branch name')}
                    {regions.length > 0 && (
                        <div className="grid gap-2">
                            <Label>{t('City or district')}</Label>
                            <select
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={form.data.region_id}
                                onChange={(e) =>
                                    form.setData(
                                        'region_id',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">—</option>
                                {Object.entries(groups).map(
                                    ([parent, items]) => (
                                        <optgroup key={parent} label={parent}>
                                            {items.map((r) => (
                                                <option key={r.id} value={r.id}>
                                                    {r.name}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ),
                                )}
                            </select>
                            <InputError message={form.errors.region_id} />
                        </div>
                    )}
                </fieldset>

                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    {t('Finish setup')}
                </Button>
            </form>
        </>
    );
}

Setup.layout = {
    title: 'Welcome to ibTaxi',
    description:
        'Set up your company: a few details and you are ready to work.',
};
