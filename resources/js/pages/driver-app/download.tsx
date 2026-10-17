import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';

type Release = {
    version_code: number;
    version_name: string;
    files: Record<string, boolean>;
};

/**
 * Haydovchi ilovasini yuklab olish (qaror #37): admin panelidagi QR shu sahifaga olib keladi.
 */
export default function DriverAppDownload({
    company,
    release,
}: {
    company: string;
    release: Release;
}) {
    const { t } = useTrans();

    return (
        <>
            <Head title={t('Driver app')} />
            <div className="mx-auto flex min-h-svh max-w-md flex-col justify-center gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">{company}</h1>
                    <p className="text-muted-foreground">
                        {t('Driver app')}{' '}
                        {release.version_name && `· ${release.version_name}`}
                    </p>
                </div>
                {release.version_code === 0 ? (
                    <p>{t('The app has not been published yet.')}</p>
                ) : (
                    <div className="grid gap-3">
                        {release.files['arm64-v8a'] && (
                            <Button asChild size="lg">
                                <a href="/driver-app/arm64-v8a.apk">
                                    <Download /> {t('Download (most phones)')}
                                </a>
                            </Button>
                        )}
                        {release.files['armeabi-v7a'] && (
                            <Button asChild variant="outline">
                                <a href="/driver-app/armeabi-v7a.apk">
                                    {t('Download for older phones')}
                                </a>
                            </Button>
                        )}
                    </div>
                )}
                <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>{t('Download the file and open it.')}</li>
                    <li>
                        {t(
                            'Allow installing from this source when Android asks.',
                        )}
                    </li>
                    <li>
                        {t(
                            'Log in with the phone number and password given by the administrator.',
                        )}
                    </li>
                    <li>
                        {t(
                            'Further updates are installed from inside the app.',
                        )}
                    </li>
                </ol>
            </div>
        </>
    );
}
