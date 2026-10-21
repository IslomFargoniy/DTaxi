import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import StaffController from '@/actions/App/Http/Controllers/Admin/StaffController';
import { DataTable } from '@/components/data-table';
import { EditDialog } from '@/components/edit-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PhoneInput } from '@/components/phone-input';
import { formatPhone } from '@/lib/format';
import { useTrans } from '@/hooks/use-trans';
import { index } from '@/routes/admin/staff';

type StaffUser = {
    id: number;
    name: string;
    username: string | null;
    email: string | null;
    phone: string | null;
    role: string | null;
    branch_id: number | null;
    branch: string | null;
    extra_branch_ids: number[];
    is_active: boolean;
};

type Props = {
    users: StaffUser[];
    roles: string[];
    branches: { id: number; name: string }[];
};

const roleLabel: Record<string, string> = {
    superadmin: 'Superadmin',
    admin: 'Branch admin',
    operator: 'Operator',
    cashier: 'Cashier',
};

const STAFF_INPUT = {
    name: { maxLength: 100, autoComplete: 'off' },
    username: {
        maxLength: 50,
        autoComplete: 'off',
        autoCapitalize: 'none',
        spellCheck: false,
    },
    email: { type: 'email', maxLength: 150, autoComplete: 'off' },
} as const;

function StaffDialog({
    user,
    roles,
    branches,
    onClose,
}: { user: StaffUser | 'new'; onClose: () => void } & Omit<Props, 'users'>) {
    const { t } = useTrans();
    const existing = user === 'new' ? null : user;
    const form = useForm({
        name: existing?.name ?? '',
        username: existing?.username ?? '',
        email: existing?.email ?? '',
        phone: existing?.phone ?? '',
        password: '',
        role:
            existing?.role ??
            (roles.includes('operator') ? 'operator' : roles[0]),
        branch_id: existing?.branch_id ?? branches[0]?.id ?? null,
        extra_branch_ids: existing?.extra_branch_ids ?? ([] as number[]),
        is_active: existing?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string>;

    return (
        <EditDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={existing ? 'Edit employee' : 'New employee'}
            processing={form.processing}
            onSubmit={() =>
                existing
                    ? form.put(StaffController.update.url(existing.id), {
                          onSuccess: onClose,
                      })
                    : form.post(StaffController.store.url(), {
                          onSuccess: onClose,
                      })
            }
        >
            {(['name', 'username', 'email'] as const).map((field) => (
                <div key={field} className="grid gap-1.5">
                    <Label htmlFor={field}>
                        {t(
                            {
                                name: 'Full name',
                                username: 'Login',
                                email: 'Email (optional)',
                            }[field],
                        )}
                    </Label>
                    <Input
                        id={field}
                        {...STAFF_INPUT[field]}
                        value={form.data[field] ?? ''}
                        onChange={(e) =>
                            form.setData(
                                field,
                                // Login serverda ham kichik harfga o'tadi
                                field === 'username'
                                    ? e.target.value.toLowerCase().trim()
                                    : e.target.value,
                            )
                        }
                    />
                    <InputError message={errors[field]} />
                </div>
            ))}
            <div className="grid gap-1.5">
                <Label htmlFor="phone">{t('Phone (optional)')}</Label>
                <PhoneInput
                    id="phone"
                    autoComplete="off"
                    value={form.data.phone}
                    onChange={(v) => form.setData('phone', v)}
                />
                <InputError message={errors.phone} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="password">
                    {t(existing ? 'New password' : 'Password')}
                </Label>
                <Input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                />
                <InputError message={errors.password} />
            </div>
            <div className="grid grid-cols-2 items-start gap-2">
                <div className="grid gap-1.5">
                    <Label>{t('Role')}</Label>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                        value={form.data.role}
                        onChange={(e) => form.setData('role', e.target.value)}
                    >
                        {roles.map((role) => (
                            <option key={role} value={role}>
                                {t(roleLabel[role] ?? role)}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.role} />
                </div>
                {form.data.role !== 'superadmin' && (
                    <div className="grid gap-1.5">
                        <Label>{t('Branch')}</Label>
                        <select
                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={form.data.branch_id ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'branch_id',
                                    Number(e.target.value),
                                )
                            }
                        >
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.branch_id} />
                    </div>
                )}
            </div>
            {form.data.role === 'operator' && branches.length > 1 && (
                <div className="grid gap-1.5">
                    <Label>{t('Also serves branches (shared queue)')}</Label>
                    <div className="flex flex-wrap gap-3">
                        {branches
                            .filter((b) => b.id !== form.data.branch_id)
                            .map((b) => (
                                <label
                                    key={b.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={form.data.extra_branch_ids.includes(
                                            b.id,
                                        )}
                                        onCheckedChange={(v) =>
                                            form.setData(
                                                'extra_branch_ids',
                                                v
                                                    ? [
                                                          ...form.data
                                                              .extra_branch_ids,
                                                          b.id,
                                                      ]
                                                    : form.data.extra_branch_ids.filter(
                                                          (id) => id !== b.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {b.name}
                                </label>
                            ))}
                    </div>
                </div>
            )}
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

export default function Staff({ users, roles, branches }: Props) {
    const { t } = useTrans();
    const [editing, setEditing] = useState<StaffUser | 'new' | null>(null);

    return (
        <>
            <Head title={t('Staff')} />
            <div className="p-4">
                <div className="mb-4 flex items-center justify-between">
                    <Heading
                        title="Staff"
                        description="Administrators, operators and cashiers."
                    />
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> {t('New employee')}
                    </Button>
                </div>
                <DataTable
                    rows={users}
                    onRowClick={setEditing}
                    columns={[
                        {
                            key: 'name',
                            title: 'Full name',
                            render: (u) => (
                                <span className="font-medium">{u.name}</span>
                            ),
                        },
                        {
                            key: 'role',
                            title: 'Role',
                            render: (u) =>
                                t(roleLabel[u.role ?? ''] ?? u.role ?? ''),
                        },
                        {
                            key: 'branch',
                            title: 'Branch',
                            render: (u) => u.branch ?? '—',
                        },
                        {
                            key: 'username',
                            title: 'Login',
                            render: (u) => u.username ?? '—',
                        },
                        {
                            key: 'phone',
                            title: 'Phone',
                            render: (u) => formatPhone(u.phone) || '—',
                        },
                        {
                            key: 'active',
                            title: 'Status',
                            render: (u) =>
                                u.is_active ? (
                                    <Badge>{t('Active')}</Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        {t('Disabled')}
                                    </Badge>
                                ),
                        },
                    ]}
                />
            </div>
            {editing && (
                <StaffDialog
                    user={editing}
                    roles={roles}
                    branches={branches}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Staff.layout = { breadcrumbs: [{ title: 'Staff', href: index() }] };
