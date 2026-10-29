<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Rules\Phone;
use App\Services\Telephony\Extensions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Xodimlar: adminlar, operatorlar, kassirlar. Filial admini faqat o'z filiali operator/kassirlarini boshqaradi.
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->manageableRoles($actor)))
            ->when(! $actor->isSuperadmin(), fn ($q) => $q->whereIn('branch_id', $actor->branchIds()))
            ->with(['roles', 'branches:id', 'branch:id,name'])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/staff', [
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->getRoleNames()->first(),
                'branch_id' => $u->branch_id,
                'branch' => $u->branch?->name,
                'extra_branch_ids' => $u->branches->pluck('id'),
                'is_active' => $u->is_active,
            ]),
            'roles' => $this->manageableRoles($actor),
            'branches' => $this->branches($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new User);

        return to_route('admin.staff.index');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(in_array($user->getRoleNames()->first(), $this->manageableRoles($actor), true), 403);
        abort_unless($actor->isSuperadmin() || in_array($user->branch_id, $actor->branchIds(), true), 403);

        $this->save($request, $user);

        return to_route('admin.staff.index');
    }

    private function save(Request $request, User $user): void
    {
        /** @var User $actor */
        $actor = $request->user();
        $branchIds = $this->branches($actor)->pluck('id')->all();

        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25', new Phone],
            'password' => [$user->exists ? 'nullable' : 'required', Password::defaults()],
            'role' => ['required', Rule::in($this->manageableRoles($actor))],
            'branch_id' => ['nullable', 'required_unless:role,superadmin', Rule::in($branchIds)],
            'extra_branch_ids' => ['array'],
            'extra_branch_ids.*' => [Rule::in($branchIds)],
            'is_active' => ['boolean'],
        ]);

        $user->fill([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => ! empty($data['phone']) ? Client::normalizePhone($data['phone']) : null,
            'branch_id' => $data['role'] === Role::Superadmin->value ? null : $data['branch_id'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        $user->syncRoles($data['role']);
        $user->branches()->sync($data['extra_branch_ids'] ?? []);

        // Operator uchun WebRTC ichki raqam (reja §5.5)
        app(Extensions::class)->syncFor($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved')]);
    }

    /**
     * @return list<string>
     */
    private function manageableRoles(User $actor): array
    {
        return $actor->isSuperadmin()
            ? [Role::Superadmin->value, Role::Admin->value, Role::Operator->value, Role::Cashier->value]
            : [Role::Operator->value, Role::Cashier->value];
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function branches(User $actor): Collection
    {
        return Branch::query()
            ->when(! $actor->isSuperadmin(), fn ($q) => $q->whereIn('id', $actor->branchIds()))
            ->orderBy('name')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name]);
    }
}
