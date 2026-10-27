import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import TelephonyController from '@/actions/App/Http/Controllers/Admin/TelephonyController';
import { DataTable } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/telephony';
import { formatPhone } from '@/lib/format';

type Trunk = {
    id: number;
    name: string;
    title: string;
    host: string;
    port: number;
    username: string | null;
    from_user: string | null;
    max_channels: number;
    max_inbound: number | null;
    max_outbound: number | null;
    is_active: boolean;
    has_password: boolean;
    state: string | null;
};

type NumberBranch = {
    id: number;
    is_primary: boolean;
    ivr_digit: number | null;
};

type PhoneNumber = {
    id: number;
    sip_trunk_id: number;
    number: string;
    name: string | null;
    routing_mode: 'shared_queue' | 'ivr' | 'client_history';
    is_active: boolean;
    branches: NumberBranch[];
};

type BranchLimit = {
    id: number;
    name: string;
    max_channels: number | null;
    max_auto_calls: number;
};

// Sozlamalar chegaralari — TelephonyController::SETTINGS bilan bir xil
const SETTING_LIMITS: Record<string, [number, number]> = {
    'calls.max_wait_seconds': [30, 1800],
    'calls.operator_ring_seconds': [5, 120],
    'calls.auto_call_attempts': [1, 5],
    'calls.auto_call_retry_seconds': [10, 600],
    'calls.still_waiting_minutes': [0, 30],
    'calls.rating_call_delay_minutes': [0, 60],
    'calls.recordings_retention_days': [1, 3650],
    'calls.max_recording_seconds': [60, 86400],
    'calls.driver_gain': [0, 10],
    'calls.operator_outbound_limit': [0, 1000],
    'calls.operator_outbound_window_minutes': [1, 1440],
    'calls.login_code_calls_per_hour': [0, 10000],
};

type Props = {
    trunks: Trunk[];
    numbers: PhoneNumber[];
    branches: BranchLimit[];
    settings: Record<string, number | boolean>;
    asteriskOnline: boolean;
};

const ROUTING: Record<PhoneNumber['routing_mode'], string> = {
    shared_queue: 'Shared queue',
    ivr: 'IVR menu',
    client_history: 'By client history',
};

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    const { t } = useTrans();

    return (
        <div className="grid gap-1.5">
            <Label>{t(label)}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function TrunkDialog({
    trunk,
    onClose,
}: {
    trunk: Trunk | 'new';
    onClose: () => void;
}) {
    const { t } = useTrans();
    const existing = trunk === 'new' ? null : trunk;
    const form = useForm({
        name: existing?.name ?? '',
        title: existing?.title ?? '',
        host: existing?.host ?? '',
        port: existing?.port ?? 5060,
        username: existing?.username ?? '',
        password: '',
        from_user: existing?.from_user ?? '',
        max_channels: existing?.max_channels ?? 4,
        max_inbound: existing?.max_inbound ?? ('' as number | ''),
        max_outbound: existing?.max_outbound ?? ('' as number | ''),
        is_active: existing?.is_active ?? true,
    });
    const e = form.errors as Record<string, string>;
    const text = (
        key: 'name' | 'title' | 'host' | 'username' | 'from_user',
    ) => (
        <Input
            autoComplete="off"
            maxLength={key === 'host' ? 255 : 100}
            value={form.data[key]}
            onChange={(ev) =>
                // Texnik maydonlarda bo'shliq bo'lmaydi (sarlavhadan tashqari)
                form.setData(
                    key,
                    key === 'title' ? ev.target.value : ev.target.value.trim(),
                )
            }
        />
    );
    const num = (
        key: 'port' | 'max_channels' | 'max_inbound' | 'max_outbound',
    ) => (
        <Input
            type="number"
            inputMode="numeric"
            min={key === 'max_inbound' || key === 'max_outbound' ? 0 : 1}
            max={key === 'port' ? 65535 : 1000}
            step={1}
            value={form.data[key]}
            onChange={(ev) =>
                form.setData(
                    key,
                    (ev.target.value === ''
                        ? ''
                        : Number(ev.target.value)) as never,
                )
            }
        />
    );

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={existing ? 'Edit SIP trunk' : 'New SIP trunk'}
            processing={form.processing}
            onSubmit={() =>
                existing
                    ? form.put(
                          TelephonyController.updateTrunk.url(existing.id),
                          {
                              onSuccess: onClose,
                          },
                      )
                    : form.post(TelephonyController.storeTrunk.url(), {
                          onSuccess: onClose,
                      })
            }
        >
            <div className="grid grid-cols-2 items-start gap-2">
                <Field label="Code (latin)" error={e.name}>
                    {text('name')}
                </Field>
                <Field label="Name" error={e.title}>
                    {text('title')}
                </Field>
            </div>
            <div className="grid grid-cols-[1fr_100px] items-start gap-2">
                <Field label="SIP host" error={e.host}>
                    {text('host')}
                </Field>
                <Field label="Port" error={e.port}>
                    {num('port')}
                </Field>
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <Field label="Login" error={e.username}>
                    {text('username')}
                </Field>
                <Field label="Password" error={e.password}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        placeholder={existing?.has_password ? '••••••' : ''}
                        value={form.data.password}
                        onChange={(ev) =>
                            form.setData('password', ev.target.value)
                        }
                    />
                </Field>
            </div>
            <Field
                label="From user (if the provider requires)"
                error={e.from_user}
            >
                {text('from_user')}
            </Field>
            <div className="grid grid-cols-3 items-start gap-2">
                <Field label="Channels" error={e.max_channels}>
                    {num('max_channels')}
                </Field>
                <Field label="Max inbound" error={e.max_inbound}>
                    {num('max_inbound')}
                </Field>
                <Field label="Max outbound" error={e.max_outbound}>
                    {num('max_outbound')}
                </Field>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_active}
                    onCheckedChange={(v) =>
                        form.setData('is_active', v === true)
                    }
                />
                {t('Active')}
            </label>
        </EditDialog>
    );
}

function NumberDialog({
    number,
    trunks,
    branches,
    onClose,
}: {
    number: PhoneNumber | 'new';
    trunks: Trunk[];
    branches: BranchLimit[];
    onClose: () => void;
}) {
    const { t } = useTrans();
    const existing = number === 'new' ? null : number;
    const form = useForm({
        sip_trunk_id: existing?.sip_trunk_id ?? trunks[0]?.id ?? 0,
        number: existing?.number ?? '',
        name: existing?.name ?? '',
        routing_mode: existing?.routing_mode ?? 'shared_queue',
        is_active: existing?.is_active ?? true,
        branches: existing?.branches ?? ([] as NumberBranch[]),
    });
    const e = form.errors as Record<string, string>;
    const linked = (id: number) => form.data.branches.find((b) => b.id === id);
    const setBranch = (id: number, patch: Partial<NumberBranch> | null) =>
        form.setData(
            'branches',
            patch === null
                ? form.data.branches.filter((b) => b.id !== id)
                : linked(id)
                  ? form.data.branches.map((b) =>
                        b.id === id ? { ...b, ...patch } : b,
                    )
                  : [
                        ...form.data.branches,
                        { id, is_primary: false, ivr_digit: null, ...patch },
                    ],
        );

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={existing ? 'Edit phone number' : 'New phone number'}
            processing={form.processing}
            onSubmit={() =>
                existing
                    ? form.put(
                          TelephonyController.updateNumber.url(existing.id),
                          {
                              onSuccess: onClose,
                          },
                      )
                    : form.post(TelephonyController.storeNumber.url(), {
                          onSuccess: onClose,
                      })
            }
        >
            <div className="grid grid-cols-2 items-start gap-2">
                <Field label="Number" error={e.number}>
                    <Input
                        placeholder="+998712000000"
                        inputMode="tel"
                        autoComplete="off"
                        maxLength={16}
                        value={form.data.number}
                        onChange={(ev) =>
                            // Trunk DID'i aynan shu ko'rinishda keladi — faqat raqam va "+"
                            form.setData(
                                'number',
                                ev.target.value.replace(/[^\d+]/g, ''),
                            )
                        }
                    />
                </Field>
                <Field label="Name" error={e.name}>
                    <Input
                        maxLength={100}
                        value={form.data.name}
                        onChange={(ev) => form.setData('name', ev.target.value)}
                    />
                </Field>
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <Field label="SIP trunk" error={e.sip_trunk_id}>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={form.data.sip_trunk_id}
                        onChange={(ev) =>
                            form.setData(
                                'sip_trunk_id',
                                Number(ev.target.value),
                            )
                        }
                    >
                        {trunks.map((tr) => (
                            <option key={tr.id} value={tr.id}>
                                {tr.title}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="If several branches" error={e.routing_mode}>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={form.data.routing_mode}
                        onChange={(ev) =>
                            form.setData(
                                'routing_mode',
                                ev.target.value as PhoneNumber['routing_mode'],
                            )
                        }
                    >
                        {Object.entries(ROUTING).map(([value, label]) => (
                            <option key={value} value={value}>
                                {t(label)}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            <div className="grid gap-1.5">
                <Label>{t('Branches served by this number')}</Label>
                <div className="grid gap-1 rounded-md border p-2">
                    {branches.map((b) => {
                        const link = linked(b.id);

                        return (
                            <div
                                key={b.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={!!link}
                                    onCheckedChange={(v) =>
                                        setBranch(b.id, v ? {} : null)
                                    }
                                />
                                <span className="flex-1">{b.name}</span>
                                {link && (
                                    <>
                                        <label className="flex items-center gap-1 text-xs">
                                            <Checkbox
                                                checked={link.is_primary}
                                                onCheckedChange={(v) =>
                                                    setBranch(b.id, {
                                                        is_primary: v === true,
                                                    })
                                                }
                                            />
                                            {t('Primary')}
                                        </label>
                                        {form.data.routing_mode !==
                                            'shared_queue' && (
                                            <Input
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={9}
                                                step={1}
                                                placeholder={t('Key')}
                                                className="h-7 w-16"
                                                value={link.ivr_digit ?? ''}
                                                onChange={(ev) =>
                                                    setBranch(b.id, {
                                                        ivr_digit:
                                                            ev.target.value ===
                                                            ''
                                                                ? null
                                                                : Number(
                                                                      ev.target
                                                                          .value,
                                                                  ),
                                                    })
                                                }
                                            />
                                        )}
                                    </>
                                )}
                            </div>
                        );
                    })}
                </div>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'No branch selected — the number serves all branches. IVR: the client presses the key to choose a branch.',
                    )}
                </p>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_active}
                    onCheckedChange={(v) =>
                        form.setData('is_active', v === true)
                    }
                />
                {t('Active')}
            </label>
        </EditDialog>
    );
}

function LimitsForm({ branches }: { branches: BranchLimit[] }) {
    const { t } = useTrans();
    const form = useForm({
        branches: branches.map((b) => ({
            id: b.id,
            max_channels: b.max_channels ?? ('' as number | ''),
            max_auto_calls: b.max_auto_calls,
        })),
    });
    const set = (
        i: number,
        key: 'max_channels' | 'max_auto_calls',
        value: string,
    ) =>
        form.setData(
            'branches',
            form.data.branches.map((row, j) =>
                j === i
                    ? { ...row, [key]: value === '' ? '' : Number(value) }
                    : row,
            ),
        );

    return (
        <form
            className="grid gap-2"
            onSubmit={(ev) => {
                ev.preventDefault();
                form.transform((data) => ({
                    branches: data.branches.map((b) => ({
                        ...b,
                        max_channels:
                            b.max_channels === '' ? null : b.max_channels,
                    })),
                }));
                form.put(TelephonyController.updateLimits.url(), {
                    preserveScroll: true,
                });
            }}
        >
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2">{t('Branch')}</th>
                            <th className="px-3 py-2">
                                {t('Max parallel calls')}
                            </th>
                            <th className="px-3 py-2">
                                {t('Auto calls (TTS)')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {branches.map((b, i) => (
                            <tr key={b.id} className="border-t">
                                <td className="px-3 py-1.5">{b.name}</td>
                                <td className="px-3 py-1.5">
                                    <Input
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={500}
                                        step={1}
                                        className="h-8 w-24"
                                        placeholder={t('Trunk')}
                                        value={
                                            form.data.branches[i].max_channels
                                        }
                                        onChange={(ev) =>
                                            set(
                                                i,
                                                'max_channels',
                                                ev.target.value,
                                            )
                                        }
                                    />
                                </td>
                                <td className="px-3 py-1.5">
                                    <Input
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={100}
                                        step={1}
                                        className="h-8 w-24"
                                        value={
                                            form.data.branches[i].max_auto_calls
                                        }
                                        onChange={(ev) =>
                                            set(
                                                i,
                                                'max_auto_calls',
                                                ev.target.value,
                                            )
                                        }
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div>
                <Button type="submit" size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

const SETTING_FIELDS: {
    key: string;
    label: string;
    kind: 'number' | 'bool' | 'list';
    hint?: string;
}[] = [
    {
        key: 'calls.max_wait_seconds',
        label: 'Max wait in queue, seconds',
        kind: 'number',
    },
    {
        key: 'calls.operator_ring_seconds',
        label: 'Operator ring time, seconds',
        kind: 'number',
    },
    {
        key: 'calls.pause_on_missed_ring',
        label: 'Pause operator who missed a call',
        kind: 'bool',
    },
    {
        key: 'calls.ivr_auto_order',
        label: 'IVR auto-order: regular client presses 1 to order a taxi to the last address',
        kind: 'bool',
    },
    {
        key: 'calls.auto_calls_enabled',
        label: 'Voice notifications to clients',
        kind: 'bool',
    },
    {
        key: 'calls.auto_call_attempts',
        label: 'Auto call attempts',
        kind: 'number',
    },
    {
        key: 'calls.auto_call_retry_seconds',
        label: 'Retry after, seconds',
        kind: 'number',
    },
    {
        key: 'calls.still_waiting_minutes',
        label: '"Driver is still waiting" after, minutes',
        kind: 'number',
    },
    {
        key: 'calls.rating_call_enabled',
        label: 'After the trip call the client to rate it (1–5)',
        kind: 'bool',
    },
    {
        key: 'calls.client_app_calls',
        label: 'Calls from the client app over the internet (to the driver and dispatcher)',
        kind: 'bool',
    },
    {
        key: 'calls.rating_call_delay_minutes',
        label: 'Rating call after, minutes',
        kind: 'number',
    },
    {
        key: 'calls.recordings_retention_days',
        label: 'Keep recordings, days',
        kind: 'number',
    },
    {
        key: 'calls.max_recording_seconds',
        label: 'Max recording length, seconds',
        kind: 'number',
    },
    {
        key: 'calls.driver_gain',
        label: 'Operator voice boost for drivers (0 — off)',
        kind: 'number',
    },
    {
        key: 'calls.operator_outbound_limit',
        label: 'Outgoing calls per operator (0 — no limit)',
        kind: 'number',
    },
    {
        key: 'calls.operator_outbound_window_minutes',
        label: 'Outgoing call limit period, minutes',
        kind: 'number',
    },
    {
        key: 'calls.login_code_calls_per_hour',
        label: 'Client app sign-in code calls per hour (0 — off)',
        kind: 'number',
    },
    {
        key: 'calls.outbound_allowed_patterns',
        label: 'Allowed outgoing numbers',
        kind: 'list',
        hint: 'One pattern per line (regular expression on digits, e.g. ^998\\d{9}$ — Uzbekistan only). Protects against calls abroad at the firm’s expense.',
    },
];

function SettingsForm({ settings }: { settings: Props['settings'] }) {
    const { t } = useTrans();
    const form = useForm<Record<string, number | boolean | string[]>>(
        Object.fromEntries(
            SETTING_FIELDS.map((f) => [
                f.key.replace('.', '_'),
                settings[f.key],
            ]),
        ),
    );
    const e = form.errors as Record<string, string>;

    return (
        <form
            className="grid items-start gap-3 sm:grid-cols-2"
            onSubmit={(ev) => {
                ev.preventDefault();
                // Bo'sh qatorlar shablon emas
                form.transform((data) => ({
                    ...data,
                    calls_outbound_allowed_patterns: (
                        (data.calls_outbound_allowed_patterns as string[]) ?? []
                    )
                        .map((p) => p.trim())
                        .filter(Boolean),
                }));
                form.put(TelephonyController.updateSettings.url(), {
                    preserveScroll: true,
                });
            }}
        >
            {SETTING_FIELDS.map((f) => {
                const key = f.key.replace('.', '_');

                if (f.kind === 'list') {
                    const lines =
                        (form.data[key] as string[] | undefined) ?? [];
                    const error =
                        e[key] ??
                        Object.entries(e).find(([k]) =>
                            k.startsWith(`${key}.`),
                        )?.[1];

                    return (
                        <Field key={key} label={f.label} error={error}>
                            <textarea
                                rows={3}
                                className="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs"
                                value={lines.join('\n')}
                                onChange={(ev) =>
                                    form.setData(
                                        key,
                                        ev.target.value.split('\n'),
                                    )
                                }
                            />
                            {f.hint && (
                                <p className="text-xs text-muted-foreground">
                                    {t(f.hint)}
                                </p>
                            )}
                        </Field>
                    );
                }

                return f.kind === 'bool' ? (
                    <label
                        key={key}
                        className="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            checked={!!form.data[key]}
                            onCheckedChange={(v) =>
                                form.setData(key, v === true)
                            }
                        />
                        {t(f.label)}
                    </label>
                ) : (
                    <Field key={key} label={f.label} error={e[key]}>
                        <Input
                            type="number"
                            inputMode="numeric"
                            min={SETTING_LIMITS[key]?.[0]}
                            max={SETTING_LIMITS[key]?.[1]}
                            step={1}
                            value={Number(form.data[key])}
                            onChange={(ev) =>
                                form.setData(key, Number(ev.target.value))
                            }
                        />
                    </Field>
                );
            })}
            <div className="sm:col-span-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

/**
 * Telefoniya (§5.5): SIP trunklar, raqamlar ↔ filiallar, liniyalar limiti, qo'ng'iroq sozlamalari.
 */
export default function Telephony({
    trunks,
    numbers,
    branches,
    settings,
    asteriskOnline,
}: Props) {
    const { t } = useTrans();
    const [trunk, setTrunk] = useState<Trunk | 'new' | null>(null);
    const [number, setNumber] = useState<PhoneNumber | 'new' | null>(null);
    const branchName = (id: number) =>
        branches.find((b) => b.id === id)?.name ?? `#${id}`;

    const remove = (url: string) => {
        if (window.confirm(t('Delete?'))) {
            router.delete(url, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={t('Telephony')} />
            <div className="grid gap-8 p-4">
                {!asteriskOnline && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {t(
                                'Asterisk is not reachable (ARI). Calls are not working.',
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <Heading
                            title="SIP trunks"
                            description="Provider accounts (e.g. Sarkor Telecom) and channel limits."
                        />
                        <Button onClick={() => setTrunk('new')}>
                            <Plus /> {t('Add')}
                        </Button>
                    </div>
                    <DataTable
                        rows={trunks}
                        onRowClick={setTrunk}
                        columns={[
                            {
                                key: 'title',
                                title: 'Name',
                                render: (r) => (
                                    <span className="font-medium">
                                        {r.title}
                                    </span>
                                ),
                            },
                            {
                                key: 'host',
                                title: 'SIP host',
                                render: (r) => `${r.host}:${r.port}`,
                            },
                            {
                                key: 'login',
                                title: 'Login',
                                render: (r) => r.username ?? '—',
                            },
                            {
                                key: 'channels',
                                title: 'Channels',
                                render: (r) =>
                                    `${r.max_channels}` +
                                    (r.max_inbound || r.max_outbound
                                        ? ` (${r.max_inbound ?? '∞'} / ${r.max_outbound ?? '∞'})`
                                        : ''),
                            },
                            {
                                key: 'state',
                                title: 'Status',
                                render: (r) =>
                                    !r.is_active ? (
                                        <Badge variant="secondary">
                                            {t('Disabled')}
                                        </Badge>
                                    ) : r.state === 'online' ? (
                                        <Badge className="bg-green-600">
                                            {t('Connected')}
                                        </Badge>
                                    ) : (
                                        <Badge variant="destructive">
                                            {t('Not connected')}
                                        </Badge>
                                    ),
                            },
                            {
                                key: 'actions',
                                title: '',
                                className: 'w-10',
                                render: (r) => (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t('Delete')}
                                        onClick={(ev) => {
                                            ev.stopPropagation();
                                            remove(
                                                TelephonyController.destroyTrunk.url(
                                                    r.id,
                                                ),
                                            );
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                ),
                            },
                        ]}
                    />
                </section>

                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <Heading
                            title="Phone numbers"
                            description="Which branches each number serves."
                        />
                        <Button
                            onClick={() => setNumber('new')}
                            disabled={trunks.length === 0}
                        >
                            <Plus /> {t('Add')}
                        </Button>
                    </div>
                    <DataTable
                        rows={numbers}
                        onRowClick={setNumber}
                        columns={[
                            {
                                key: 'number',
                                title: 'Number',
                                render: (r) => (
                                    <span className="font-medium">
                                        {formatPhone(r.number)}
                                    </span>
                                ),
                            },
                            {
                                key: 'name',
                                title: 'Name',
                                render: (r) => r.name ?? '—',
                            },
                            {
                                key: 'branches',
                                title: 'Branches',
                                render: (r) =>
                                    r.branches.length === 0
                                        ? t('All branches')
                                        : r.branches
                                              .map(
                                                  (b) =>
                                                      branchName(b.id) +
                                                      (b.is_primary
                                                          ? ' ★'
                                                          : '') +
                                                      (b.ivr_digit
                                                          ? ` [${b.ivr_digit}]`
                                                          : ''),
                                              )
                                              .join(', '),
                            },
                            {
                                key: 'routing',
                                title: 'Routing',
                                render: (r) => t(ROUTING[r.routing_mode]),
                            },
                            {
                                key: 'active',
                                title: 'Status',
                                render: (r) =>
                                    r.is_active ? (
                                        <Badge>{t('Active')}</Badge>
                                    ) : (
                                        <Badge variant="secondary">
                                            {t('Disabled')}
                                        </Badge>
                                    ),
                            },
                            {
                                key: 'actions',
                                title: '',
                                className: 'w-10',
                                render: (r) => (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t('Delete')}
                                        onClick={(ev) => {
                                            ev.stopPropagation();
                                            remove(
                                                TelephonyController.destroyNumber.url(
                                                    r.id,
                                                ),
                                            );
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                ),
                            },
                        ]}
                    />
                </section>

                <section>
                    <Heading
                        title="Branch line limits"
                        description="Branch share of the trunk channels; a separate share for auto calls so operators always have a free line."
                    />
                    <div className="mt-3">
                        <LimitsForm branches={branches} />
                    </div>
                </section>

                <section>
                    <Heading
                        title="Call center settings"
                        description="Queue, operator ringing and voice notifications."
                    />
                    <div className="mt-3 max-w-2xl">
                        <SettingsForm settings={settings} />
                    </div>
                </section>
            </div>

            {trunk && (
                <TrunkDialog trunk={trunk} onClose={() => setTrunk(null)} />
            )}
            {number && (
                <NumberDialog
                    number={number}
                    trunks={trunks}
                    branches={branches}
                    onClose={() => setNumber(null)}
                />
            )}
        </>
    );
}

Telephony.layout = { breadcrumbs: [{ title: 'Telephony', href: index() }] };
