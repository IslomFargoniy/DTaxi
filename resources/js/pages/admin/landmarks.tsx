import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import LandmarkController from '@/actions/App/Http/Controllers/Admin/LandmarkController';
import { DataTable, Pagination } from '@/components/data-table';
import type { Paginated } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { SearchInput } from '@/components/search-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/landmarks';

type Landmark = {
    id: number;
    name: string;
    aliases: string[];
    category: string | null;
    usage_count: number;
    lat: number;
    lng: number;
};

function LandmarkDialog({
    landmark,
    onClose,
}: {
    landmark: Landmark;
    onClose: () => void;
}) {
    const { t } = useTrans();
    const form = useForm({
        name: landmark.name,
        aliases: landmark.aliases.join(', '),
        category: landmark.category ?? '',
        lat: landmark.lat,
        lng: landmark.lng,
    });

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Edit landmark"
            processing={form.processing}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    aliases: data.aliases
                        .split(',')
                        .map((a) => a.trim())
                        .filter(Boolean),
                }));
                form.put(LandmarkController.update.url(landmark.id), {
                    preserveScroll: true,
                    onSuccess: onClose,
                });
            }}
        >
            <div className="grid gap-1.5">
                <Label htmlFor="name">{t('Name')}</Label>
                <Input
                    id="name"
                    maxLength={150}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="aliases">
                    {t('Other names (comma separated)')}
                </Label>
                <Input
                    id="aliases"
                    value={form.data.aliases}
                    onChange={(e) => form.setData('aliases', e.target.value)}
                />
                <InputError
                    message={
                        Object.entries(
                            form.errors as Record<string, string>,
                        ).find(([k]) => k.startsWith('aliases'))?.[1]
                    }
                />
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <div className="grid gap-1.5">
                    <Label>{t('Latitude')}</Label>
                    <Input
                        type="number"
                        inputMode="decimal"
                        step="0.00001"
                        min={37}
                        max={46}
                        value={form.data.lat}
                        onChange={(e) =>
                            form.setData('lat', Number(e.target.value))
                        }
                    />
                    <InputError message={form.errors.lat} />
                </div>
                <div className="grid gap-1.5">
                    <Label>{t('Longitude')}</Label>
                    <Input
                        type="number"
                        inputMode="decimal"
                        step="0.00001"
                        min={55}
                        max={74}
                        value={form.data.lng}
                        onChange={(e) =>
                            form.setData('lng', Number(e.target.value))
                        }
                    />
                    <InputError message={form.errors.lng} />
                </div>
            </div>
        </EditDialog>
    );
}

export default function Landmarks({
    landmarks,
    filters,
}: {
    landmarks: Paginated<Landmark>;
    filters: { q: string };
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<Landmark | null>(null);

    return (
        <>
            <Head title={t('Landmarks')} />
            <div className="p-4">
                <Heading
                    title="Landmarks"
                    description="Places clients name on the phone. Operators add new ones from the map."
                />
                <div className="mb-3">
                    <SearchInput value={filters.q} />
                </div>
                <DataTable
                    rows={landmarks.data}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'name',
                            title: 'Name',
                            render: (l) => (
                                <span className="font-medium">{l.name}</span>
                            ),
                        },
                        {
                            key: 'aliases',
                            title: 'Other names',
                            render: (l) => (
                                <span className="text-xs text-muted-foreground">
                                    {l.aliases.join(', ')}
                                </span>
                            ),
                        },
                        {
                            key: 'usage',
                            title: 'Used',
                            className: 'text-right',
                            render: (l) => l.usage_count,
                        },
                        {
                            key: 'actions',
                            title: '',
                            className: 'w-10',
                            render: (l) => (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (
                                            window.confirm(
                                                t('Delete this landmark?'),
                                            )
                                        ) {
                                            router.delete(
                                                LandmarkController.destroy.url(
                                                    l.id,
                                                ),
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            ),
                        },
                    ]}
                />
                <Pagination links={landmarks.links} />
            </div>
            {editing && (
                <LandmarkDialog
                    landmark={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Landmarks.layout = { breadcrumbs: [{ title: 'Landmarks', href: index() }] };
