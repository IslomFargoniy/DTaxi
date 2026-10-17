import { Head, useForm } from '@inertiajs/react';
import IntegrationController from '@/actions/App/Http/Controllers/Admin/IntegrationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { index } from '@/routes/admin/integrations';

type Field = {
    key: string;
    label: string;
    secret: boolean;
    type?: 'select' | 'textarea';
    options?: string[];
};

type Integration = {
    provider: string;
    label: string;
    fields: Field[];
    is_enabled: boolean;
    values: Record<string, string | null>;
};

function IntegrationCard({ integration }: { integration: Integration }) {
    const { t } = useTrans();
    const configured = Object.values(integration.values).some(Boolean);

    // Maxfiy maydonlar bo'sh ko'rsatiladi: bo'sh qoldirilsa serverda eski qiymat saqlanadi
    const { data, setData, put, processing, errors } = useForm({
        is_enabled: integration.is_enabled,
        credentials: Object.fromEntries(
            integration.fields.map((f) => [
                f.key,
                f.secret ? '' : (integration.values[f.key] ?? ''),
            ]),
        ) as Record<string, string>,
    });

    const setCredential = (key: string, value: string) =>
        setData('credentials', { ...data.credentials, [key]: value });

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>{integration.label}</CardTitle>
                <Badge
                    variant={integration.is_enabled ? 'default' : 'secondary'}
                >
                    {integration.is_enabled
                        ? t('Enabled')
                        : configured
                          ? t('Disabled')
                          : t('Not configured')}
                </Badge>
            </CardHeader>
            <CardContent>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(
                            IntegrationController.update.url(
                                integration.provider,
                            ),
                            {
                                preserveScroll: true,
                            },
                        );
                    }}
                >
                    {integration.fields.map((field) => {
                        const id = `${integration.provider}-${field.key}`;
                        const error = (errors as Record<string, string>)[
                            `credentials.${field.key}`
                        ];

                        return (
                            <div key={field.key} className="grid gap-2">
                                <Label htmlFor={id}>{field.label}</Label>
                                {field.type === 'select' ? (
                                    <Select
                                        value={
                                            data.credentials[field.key] ||
                                            undefined
                                        }
                                        onValueChange={(v) =>
                                            setCredential(field.key, v)
                                        }
                                    >
                                        <SelectTrigger id={id}>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {field.options?.map((option) => (
                                                <SelectItem
                                                    key={option}
                                                    value={option}
                                                >
                                                    {option}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : field.type === 'textarea' ? (
                                    <textarea
                                        id={id}
                                        rows={4}
                                        className="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs"
                                        value={data.credentials[field.key]}
                                        placeholder={
                                            integration.values[field.key] ?? ''
                                        }
                                        onChange={(e) =>
                                            setCredential(
                                                field.key,
                                                e.target.value,
                                            )
                                        }
                                    />
                                ) : (
                                    <Input
                                        id={id}
                                        type={
                                            field.secret ? 'password' : 'text'
                                        }
                                        autoComplete="off"
                                        value={data.credentials[field.key]}
                                        placeholder={
                                            integration.values[field.key] ?? ''
                                        }
                                        onChange={(e) =>
                                            setCredential(
                                                field.key,
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                                {field.secret &&
                                    integration.values[field.key] && (
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'Leave empty to keep the current value',
                                            )}
                                        </p>
                                    )}
                                <InputError message={error} />
                            </div>
                        );
                    })}

                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`${integration.provider}-enabled`}
                            checked={data.is_enabled}
                            onCheckedChange={(v) =>
                                setData('is_enabled', v === true)
                            }
                        />
                        <Label htmlFor={`${integration.provider}-enabled`}>
                            {t('Enabled')}
                        </Label>
                    </div>

                    <div>
                        <Button disabled={processing}>{t('Save')}</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export default function Integrations({
    integrations,
}: {
    integrations: Integration[];
}) {
    const { t } = useTrans();

    return (
        <>
            <Head title={t('Integrations')} />
            <div className="p-4">
                <Heading
                    title="Integrations"
                    description="External services the company has contracts with. Keys are stored encrypted."
                />
                <div className="grid gap-4 lg:grid-cols-2">
                    {integrations.map((integration) => (
                        <IntegrationCard
                            key={integration.provider}
                            integration={integration}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

Integrations.layout = {
    breadcrumbs: [{ title: 'Integrations', href: index() }],
};
