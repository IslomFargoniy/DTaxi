import { Head, useForm } from '@inertiajs/react';
import DriverAppController from '@/actions/App/Http/Controllers/DriverAppController';
import { formatDateTime } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { QrCode } from '@/components/qr-code';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/driver-app';

type Release = {
    version_code: number;
    version_name: string;
    min_version_code: number;
    released_at: string | null;
    files: Record<string, boolean>;
};

export default function DriverApp({
    release,
    downloadPage,
}: {
    release: Release;
    downloadPage: string;
}) {
    const { t } = useTrans();
    const form = useForm<{
        version_code: number;
        version_name: string;
        min_version_code: number;
        apk: Record<string, File | null>;
    }>({
        version_code: release.version_code + 1,
        version_name: '',
        min_version_code: release.min_version_code,
        apk: { 'arm64-v8a': null, 'armeabi-v7a': null },
    });
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title={t('Driver app')} />
            <div className="grid gap-4 p-4 lg:grid-cols-2">
                <div className="lg:col-span-2">
                    <Heading
                        title="Driver app"
                        description="Distributed from this server without Google Play. Drivers install it by the QR code; updates arrive inside the app."
                    />
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Current version')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        <div>
                            {release.version_code
                                ? `${release.version_name} (${release.version_code})`
                                : t('Not published yet')}{' '}
                            · {formatDateTime(release.released_at)}
                        </div>
                        <div className="text-muted-foreground">
                            {t('Minimum version')}: {release.min_version_code}
                        </div>
                        <QrCode value={downloadPage} />
                        <a
                            href={downloadPage}
                            className="text-xs underline"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {downloadPage}
                        </a>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Publish a new version')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(DriverAppController.publish.url(), {
                                    forceFormData: true,
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div className="grid grid-cols-3 items-start gap-2">
                                <div className="grid gap-1.5">
                                    <Label>{t('Version code')}</Label>
                                    <Input
                                        type="number"
                                        value={form.data.version_code}
                                        onChange={(e) =>
                                            form.setData(
                                                'version_code',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError message={errors.version_code} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>{t('Version name')}</Label>
                                    <Input
                                        placeholder="1.1.0"
                                        value={form.data.version_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'version_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.version_name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>{t('Minimum version')}</Label>
                                    <Input
                                        type="number"
                                        value={form.data.min_version_code}
                                        onChange={(e) =>
                                            form.setData(
                                                'min_version_code',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.min_version_code}
                                    />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Drivers below the minimum version must update before going on air.',
                                )}
                            </p>
                            {(['arm64-v8a', 'armeabi-v7a'] as const).map(
                                (abi) => (
                                    <div key={abi} className="grid gap-1.5">
                                        <Label>APK · {abi}</Label>
                                        <Input
                                            type="file"
                                            accept=".apk"
                                            onChange={(e) =>
                                                form.setData('apk', {
                                                    ...form.data.apk,
                                                    [abi]:
                                                        e.target.files?.[0] ??
                                                        null,
                                                })
                                            }
                                        />
                                    </div>
                                ),
                            )}
                            <InputError message={errors.apk} />
                            <div>
                                <Button disabled={form.processing}>
                                    {t('Publish')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

DriverApp.layout = { breadcrumbs: [{ title: 'Driver app', href: index() }] };
