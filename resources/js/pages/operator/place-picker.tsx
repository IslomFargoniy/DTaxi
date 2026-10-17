import { MapPin, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';
import { getJson, postJson } from '@/lib/http';
import { landmarks as searchLandmarks } from '@/routes/operator/lookup';
import { store as storeLandmark } from '@/routes/operator/landmarks';
import type { Landmark } from '@/types/operator';

export type PickedPlace = {
    text: string;
    lat: number | null;
    lng: number | null;
    landmarkId: number | null;
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
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        clearTimeout(timer.current);

        if (!open || value.text.trim().length < 2 || value.landmarkId) {
            setResults([]);

            return;
        }

        timer.current = setTimeout(() => {
            getJson<Landmark[]>(
                searchLandmarks.url({
                    query: {
                        q: value.text,
                        ...(near ? { lat: near.lat, lng: near.lng } : {}),
                    },
                }),
            )
                .then(setResults)
                .catch(() => setResults([]));
        }, 200);
    }, [value.text, value.landmarkId, open, near]);

    const canSaveLandmark =
        value.lat !== null &&
        !value.landmarkId &&
        value.text.trim().length >= 2;

    return (
        <div className="relative grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {t(label)}
            </label>
            <div className="flex gap-1.5">
                <Input
                    id={id}
                    value={value.text}
                    autoComplete="off"
                    placeholder={t('Landmark or address')}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 150)}
                    onChange={(e) =>
                        onChange({
                            ...value,
                            text: e.target.value,
                            landmarkId: null,
                        })
                    }
                />
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
                            className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
                            onClick={() =>
                                postJson<{ id: number }>(storeLandmark.url(), {
                                    name: value.text,
                                    lat: value.lat,
                                    lng: value.lng,
                                })
                                    .then(({ id: landmarkId }) =>
                                        onChange({ ...value, landmarkId }),
                                    )
                                    .catch(() => undefined)
                            }
                        >
                            <Plus className="size-3" />
                            {t('Save as landmark')}
                        </button>
                    )}
                </div>
            )}

            {open && results.length > 0 && (
                <ul className="absolute top-full z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 shadow-md">
                    {results.map((landmark) => (
                        <li key={landmark.id}>
                            <button
                                type="button"
                                className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => {
                                    onChange({
                                        text: landmark.name,
                                        lat: landmark.lat,
                                        lng: landmark.lng,
                                        landmarkId: landmark.id,
                                    });
                                    setOpen(false);
                                }}
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
