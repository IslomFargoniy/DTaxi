import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Play, RefreshCw, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import SoundController from '@/actions/App/Http/Controllers/Admin/SoundController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/sounds';

type Clip = {
    id: number | null;
    category: string;
    key: string;
    text: string;
    ready: boolean;
    outdated: boolean;
    duration_ms: number | null;
    generated_at: string | null;
};

const CATEGORIES: Record<string, string> = {
    greeting: 'Greetings',
    ivr: 'IVR / queue',
    phrase: 'Phrases',
    number: 'Numbers',
    letter: 'Letters',
    color: 'Colors',
    car: 'Car models',
    branch: 'Branch names',
};

/**
 * Ovozli bo'laklar (§5.4): tinglash, matnni tuzatib qayta generatsiya, o'z yozuvini yuklash.
 */
export default function Sounds({ clips }: { clips: Clip[] }) {
    const { t } = useTrans();
    const [category, setCategory] = useState('all');
    const [onlyMissing, setOnlyMissing] = useState(false);
    const [texts, setTexts] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const audio = useRef<HTMLAudioElement | null>(null);
    const upload = useRef<HTMLInputElement | null>(null);
    const uploadFor = useRef<Clip | null>(null);

    const missing = clips.filter((c) => !c.ready || c.outdated).length;
    const rows = useMemo(
        () =>
            clips.filter(
                (c) =>
                    (category === 'all' || c.category === category) &&
                    (!onlyMissing || !c.ready || c.outdated),
            ),
        [clips, category, onlyMissing],
    );
    const id = (c: Clip) => `${c.category}/${c.key}`;
    const textOf = (c: Clip) => texts[id(c)] ?? c.text;

    const play = (c: Clip) => {
        if (c.id && audio.current) {
            audio.current.src = `${SoundController.play.url(c.id)}?t=${Date.now()}`;
            void audio.current.play();
        }
    };

    const generate = (c: Clip) => {
        setBusy(id(c));
        router.post(
            SoundController.generate.url(),
            { category: c.category, key: c.key, text: textOf(c) },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const generateMissing = () => {
        setBusy('all');
        router.post(
            SoundController.generateMissing.url(),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const onFile = (file: File | undefined) => {
        const c = uploadFor.current;
        if (!file || !c) {
            return;
        }
        setBusy(id(c));
        router.post(
            SoundController.upload.url(),
            { category: c.category, key: c.key, text: textOf(c), file },
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    return (
        <>
            <Head title={t('Voice clips')} />
            <audio ref={audio} />
            <input
                ref={upload}
                type="file"
                accept="audio/*"
                className="hidden"
                onChange={(e) => {
                    onFile(e.target.files?.[0]);
                    e.target.value = '';
                }}
            />
            <div className="p-4">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <Heading
                        title="Voice clips"
                        description="Clips for client voice messages and the IVR. Listen to every clip after generating."
                    />
                    <Button
                        onClick={generateMissing}
                        disabled={busy !== null || missing === 0}
                    >
                        <RefreshCw
                            className={cn(busy === 'all' && 'animate-spin')}
                        />
                        {t('Generate missing')} ({missing})
                    </Button>
                </div>

                <div className="mb-3 flex flex-wrap items-center gap-1">
                    {['all', ...Object.keys(CATEGORIES)].map((cat) => (
                        <Button
                            key={cat}
                            size="sm"
                            variant={category === cat ? 'default' : 'outline'}
                            onClick={() => setCategory(cat)}
                        >
                            {t(cat === 'all' ? 'All' : CATEGORIES[cat])}
                        </Button>
                    ))}
                    <label className="ml-3 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={onlyMissing}
                            onChange={(e) => setOnlyMissing(e.target.checked)}
                        />
                        {t('Only missing')}
                    </label>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2">{t('Category')}</th>
                                <th className="px-3 py-2">{t('Key')}</th>
                                <th className="px-3 py-2">{t('Text')}</th>
                                <th className="px-3 py-2">{t('Status')}</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((c) => (
                                <tr key={id(c)} className="border-t">
                                    <td className="px-3 py-1.5 text-muted-foreground">
                                        {t(
                                            CATEGORIES[c.category] ??
                                                c.category,
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5 font-mono text-xs">
                                        {c.key}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <Input
                                            className="h-8 min-w-60"
                                            value={textOf(c)}
                                            onChange={(e) =>
                                                setTexts({
                                                    ...texts,
                                                    [id(c)]: e.target.value,
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="px-3 py-1.5 whitespace-nowrap">
                                        {!c.ready ? (
                                            <Badge variant="destructive">
                                                <AlertTriangle className="size-3" />{' '}
                                                {t('Missing')}
                                            </Badge>
                                        ) : c.outdated ? (
                                            <Badge variant="secondary">
                                                {t('Text changed')}
                                            </Badge>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                {(
                                                    (c.duration_ms ?? 0) / 1000
                                                ).toFixed(1)}{' '}
                                                s
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                disabled={!c.ready}
                                                onClick={() => play(c)}
                                                title={t('Listen')}
                                            >
                                                <Play className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                disabled={busy !== null}
                                                onClick={() => generate(c)}
                                                title={t('Generate')}
                                            >
                                                <RefreshCw
                                                    className={cn(
                                                        'size-4',
                                                        busy === id(c) &&
                                                            'animate-spin',
                                                    )}
                                                />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                disabled={busy !== null}
                                                onClick={() => {
                                                    uploadFor.current = c;
                                                    upload.current?.click();
                                                }}
                                                title={t(
                                                    'Upload own recording',
                                                )}
                                            >
                                                <Upload className="size-4" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Sounds.layout = { breadcrumbs: [{ title: 'Voice clips', href: index() }] };
