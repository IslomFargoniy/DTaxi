import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import StreetRenameController from '@/actions/App/Http/Controllers/Admin/StreetRenameController';
import { DataTable } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { getJson, isAbort } from '@/lib/http';
import { index } from '@/routes/admin/street-renames';

type StreetRename = {
    id: number;
    old_name: string;
    new_name: string;
    region_id: number | null;
    region: string | null;
};

type Region = { id: number; name: string };

/** Eski nom — xaritadagi yozuv bilan aynan bir xil bo'lishi kerak, shuning uchun bazadagi ko'chalardan tanlanadi */
function useStreetSuggestions(query: string): string[] {
    const [items, setItems] = useState<string[]>([]);

    useEffect(() => {
        if (query.trim().length < 2) {
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            getJson<string[]>(
                StreetRenameController.suggest.url({ query: { q: query } }),
                { signal: controller.signal },
            )
                .then(setItems)
                .catch((error: unknown) => {
                    if (!isAbort(error)) {
                        setItems([]);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    return query.trim().length < 2 ? [] : items;
}

function RenameDialog({
    rename,
    regions,
    onClose,
}: {
    rename: StreetRename | null;
    regions: Region[];
    onClose: () => void;
}) {
    const { t } = useTrans();
    const form = useForm({
        old_name: rename?.old_name ?? '',
        new_name: rename?.new_name ?? '',
        region_id: rename?.region_id ?? null,
    });
    const suggestions = useStreetSuggestions(form.data.old_name);

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={rename ? 'Edit street name' : 'Add street name'}
            processing={form.processing}
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };
                if (rename) {
                    form.put(
                        StreetRenameController.update.url(rename.id),
                        options,
                    );
                } else {
                    form.post(StreetRenameController.store.url(), options);
                }
            }}
        >
            <div className="grid gap-1.5">
                <Label htmlFor="old_name">
                    {t('Old name (as on the map)')}
                </Label>
                <Input
                    id="old_name"
                    list="street-suggestions"
                    maxLength={150}
                    autoComplete="off"
                    value={form.data.old_name}
                    onChange={(e) => form.setData('old_name', e.target.value)}
                />
                <datalist id="street-suggestions">
                    {suggestions.map((name) => (
                        <option key={name} value={name} />
                    ))}
                </datalist>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Pick the street from the list so the spelling matches the map exactly.',
                    )}
                </p>
                <InputError message={form.errors.old_name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="new_name">{t('New name')}</Label>
                <Input
                    id="new_name"
                    maxLength={150}
                    value={form.data.new_name}
                    onChange={(e) => form.setData('new_name', e.target.value)}
                />
                <InputError message={form.errors.new_name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="region_id">{t('City or region')}</Label>
                <select
                    id="region_id"
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={form.data.region_id ?? ''}
                    onChange={(e) =>
                        form.setData(
                            'region_id',
                            e.target.value === ''
                                ? null
                                : Number(e.target.value),
                        )
                    }
                >
                    <option value="">{t('Everywhere')}</option>
                    {regions.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.name}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Only if a street with the same name exists in another city.',
                    )}
                </p>
                <InputError message={form.errors.region_id} />
            </div>
        </EditDialog>
    );
}

export default function StreetRenames({
    renames,
    regions,
}: {
    renames: StreetRename[];
    regions: Region[];
}) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<StreetRename | 'new' | null>(null);

    return (
        <>
            <Head title={t('Street names')} />
            <div className="p-4">
                <Heading
                    title="Street names"
                    description="Many streets were renamed, but the map data (OpenStreetMap) still has the old names. Add the new name here: map labels, navigation and address search will show it, and the old name still finds the street."
                />
                <div className="mb-3">
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus />
                        {t('Add')}
                    </Button>
                </div>
                <DataTable
                    rows={renames}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'old',
                            title: 'Old name',
                            render: (r) => (
                                <span className="text-muted-foreground">
                                    {r.old_name}
                                </span>
                            ),
                        },
                        {
                            key: 'new',
                            title: 'New name',
                            render: (r) => (
                                <span className="font-medium">
                                    {r.new_name}
                                </span>
                            ),
                        },
                        {
                            key: 'region',
                            title: 'City or region',
                            render: (r) => r.region ?? t('Everywhere'),
                        },
                        {
                            key: 'actions',
                            title: '',
                            className: 'w-10',
                            render: (r) => (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (
                                            window.confirm(
                                                t(
                                                    'Delete? Streets and addresses get the old name back.',
                                                ),
                                            )
                                        ) {
                                            router.delete(
                                                StreetRenameController.destroy.url(
                                                    r.id,
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
            </div>
            {editing && (
                <RenameDialog
                    rename={editing === 'new' ? null : editing}
                    regions={regions}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

StreetRenames.layout = {
    breadcrumbs: [{ title: 'Street names', href: index() }],
};
