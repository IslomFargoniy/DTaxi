import { MapPin, Plus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { getJson, isAbort, postJson } from '@/lib/http';
import { landmarks as searchLandmarks } from '@/routes/operator/lookup';
import { store as storeLandmark } from '@/routes/operator/landmarks';
import { cn } from '@/lib/utils';
import type { Landmark } from '@/types/operator';

export type PickedPlace = {
    text: string;
    lat: number | null;
    lng: number | null;
    landmarkId: number | null;
    /** Matn xaritadan avtomatik qo'yilgan (operator yozmagan) — nuqta surilsa yangilanadi */
    auto?: boolean;
};

type Props = {
    id: string;
    label: string;
    value: PickedPlace;
    onChange: (place: PickedPlace) => void;
    /** Xaritadan nuqta tanlash rejimini yoqish */
    onPickOnMap: () => void;
    picking: boolean;
    /** Qidiruvda yaqin natijalar oldinda chiqishi uchun (filial markazi) */
    near?: { lat: number; lng: number } | null;
};

/**
 * Mo'ljal tanlash: 2+ harf yozilganda bazadan takliflar (lotin/kirill farqsiz), yoki xaritada nuqta.
 * Xaritada belgilangan yangi joyni bir bosishda mo'ljallar bazasiga qo'shish mumkin (reja §4).
 */
export function PlacePicker({
    id,
    label,
    value,
    onChange,
    onPickOnMap,
    picking,
    near,
}: Props) {
    const { t } = useTrans();
    const [results, setResults] = useState<Landmark[]>([]);
    const [open, setOpen] = useState(false);
    // Klaviatura bilan tanlanayotgan taklif (↑/↓), -1 — hech biri
    const [highlight, setHighlight] = useState(-1);
    const [saving, setSaving] = useState(false);
    const blurTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    // Mo'ljal saqlanayotganda operator maydonni o'zgartirsa — javob eski qiymat ustidan yozilmasin
    const latest = useRef(value);
    useEffect(() => {
        latest.current = value;
    }, [value]);

    useEffect(() => {
        if (!open || value.text.trim().length < 2 || value.landmarkId) {
            setResults([]);
            setHighlight(-1);

            return;
        }

        // Har yangi harfda oldingi so'rov bekor — kechikkan eski javob yangi natijalarni almashtirmaydi
        const controller = new AbortController();
        const id = setTimeout(() => {
            getJson<Landmark[]>(
                searchLandmarks.url({
                    query: {
                        q: value.text,
                        ...(near ? { lat: near.lat, lng: near.lng } : {}),
                    },
                }),
                { signal: controller.signal },
            )
                .then((list) => {
                    setResults(list);
                    setHighlight(-1);
                })
                .catch((e) => !isAbort(e) && setResults([]));
        }, 200);

        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [value.text, value.landmarkId, open, near]);

    const choose = (landmark: Landmark) => {
        onChange({
            text: landmark.name,
            lat: landmark.lat,
            lng: landmark.lng,
            landmarkId: landmark.id,
        });
        setOpen(false);
        setHighlight(-1);
    };

    const saveLandmark = () => {
        const saved = value;
        if (saving || saved.lat === null) {
            return;
        }
        setSaving(true);
        postJson<{ id: number }>(storeLandmark.url(), {
            name: saved.text,
            lat: saved.lat,
            lng: saved.lng,
        })
            .then(({ id: landmarkId }) => {
                const now = latest.current;
                if (
                    now.text === saved.text &&
                    now.lat === saved.lat &&
                    now.lng === saved.lng
                ) {
                    onChange({ ...now, landmarkId });
                }
            })
            .catch(() => undefined)
            .finally(() => setSaving(false));
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        const listed = open && results.length > 0;
        if (e.key === 'ArrowDown' && listed) {
            e.preventDefault();
            setHighlight((h) => (h + 1) % results.length);
        } else if (e.key === 'ArrowUp' && listed) {
            e.preventDefault();
            setHighlight((h) => (h <= 0 ? results.length - 1 : h - 1));
        } else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
            // Enter taklifni tanlaydi, buyurtmani hech qachon yubormaydi (yuborish — Ctrl+Enter)
            e.preventDefault();
            if (listed) {
                choose(results[highlight >= 0 ? highlight : 0]);
            }
        } else if (e.key === 'Escape' && listed) {
            setOpen(false);
            setHighlight(-1);
        }
    };

    const hasValue = value.text !== '' || value.lat !== null;

    // Xaritadan avtomatik qo'yilgan matn ("Xaritadagi nuqta" yoki yaqin uy manzili) mo'ljal nomi emas —
    // operator nomni o'zi yozgandagina saqlanadi
    const canSaveLandmark =
        value.lat !== null &&
        !value.landmarkId &&
        !value.auto &&
        value.text.trim().length >= 2;

    return (
        <div className="relative grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {t(label)}
            </label>
            <div className="flex gap-1.5">
                <div className="relative flex-1">
                    <Input
                        id={id}
                        value={value.text}
                        autoComplete="off"
                        className={hasValue ? 'pr-8' : undefined}
                        placeholder={t('Landmark or address')}
                        role="combobox"
                        aria-expanded={open && results.length > 0}
                        aria-controls={`${id}-suggestions`}
                        aria-activedescendant={
                            highlight >= 0
                                ? `${id}-option-${highlight}`
                                : undefined
                        }
                        onKeyDown={onKeyDown}
                        onFocus={() => {
                            // Kechikkan "yopish" (blur'dan) qayta fokusda ro'yxatni yopib qo'ymasin
                            clearTimeout(blurTimer.current);
                            setOpen(true);
                        }}
                        onBlur={() => {
                            blurTimer.current = setTimeout(
                                () => setOpen(false),
                                150,
                            );
                        }}
                        onChange={(e) => {
                            setOpen(true);
                            onChange({
                                ...value,
                                text: e.target.value,
                                landmarkId: null,
                                // Operator o'zi yozdi — xaritadan kelgan nom ustidan yozmasin
                                auto: false,
                            });
                        }}
                    />
                    {hasValue && (
                        <button
                            type="button"
                            title={t('Clear')}
                            aria-label={t('Clear')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                            // Fokus maydondan ketmasin — tozalagach darhol yangi qidiruv yoziladi
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                                onChange({
                                    text: '',
                                    lat: null,
                                    lng: null,
                                    landmarkId: null,
                                });
                                document.getElementById(id)?.focus();
                            }}
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
                <Button
                    type="button"
                    size="icon"
                    variant={picking ? 'default' : 'outline'}
                    title={t('Pick on the map')}
                    onClick={onPickOnMap}
                >
                    <MapPin />
                </Button>
            </div>

            {value.lat !== null && (
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>
                        {value.lat.toFixed(5)}, {value.lng?.toFixed(5)}
                    </span>
                    {canSaveLandmark && (
                        <button
                            type="button"
                            disabled={saving}
                            className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline disabled:opacity-50"
                            onClick={saveLandmark}
                        >
                            <Plus className="size-3" />
                            {t('Save as landmark')}
                        </button>
                    )}
                </div>
            )}

            {open && results.length > 0 && (
                <ul
                    id={`${id}-suggestions`}
                    role="listbox"
                    className="absolute top-full z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {results.map((landmark, index) => (
                        <li
                            key={landmark.id}
                            id={`${id}-option-${index}`}
                            role="option"
                            aria-selected={index === highlight}
                            ref={(el) => {
                                if (index === highlight) {
                                    el?.scrollIntoView({ block: 'nearest' });
                                }
                            }}
                        >
                            <button
                                type="button"
                                tabIndex={-1}
                                className={cn(
                                    'w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent',
                                    index === highlight && 'bg-accent',
                                )}
                                onMouseDown={(e) => e.preventDefault()}
                                onMouseEnter={() => setHighlight(index)}
                                onClick={() => choose(landmark)}
                            >
                                {landmark.name}
                                {landmark.aliases.length > 0 && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {landmark.aliases.join(', ')}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
