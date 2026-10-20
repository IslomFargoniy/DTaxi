<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\SetLocale;
use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Birinchi kirish ustasi (reja §0, Bosqich 3b): firma nomi, til, superadmin, birinchi filial.
 * Faqat superadmin hali yo'q bo'lganda ochiladi.
 */
class SetupController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (EnsureSetupComplete::isComplete()) {
            return to_route('login');
        }

        return Inertia::render('auth/setup', [
            'regions' => Region::query()->whereNotNull('parent_id')->with('parent:id,name_uz,name_ru')
                ->orderBy('parent_id')->orderBy('name_uz')->get()
                ->map(fn (Region $r) => ['id' => $r->id, 'name' => $r->name(), 'parent' => $r->parent?->name()]),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        abort_if(EnsureSetupComplete::isComplete(), 403);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'locale' => ['required', Rule::in(SetLocale::LOCALES)],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'branch_name' => ['required', 'string', 'max:100'],
            'region_id' => ['nullable', Rule::exists('regions', 'id')->whereNotNull('parent_id')],
        ]);

        $user = DB::transaction(function () use ($data, $settings) {
            Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);

            $settings->set('company.name', $data['company_name']);
            $settings->set('company.default_locale', $data['locale']);

            $branch = new Branch(['name' => $data['branch_name'], 'service_name' => $data['company_name']]);
            if (! empty($data['region_id'])) {
                $branch->fillAreaFromRegion(Region::query()->whereKey($data['region_id'])->firstOrFail());
            }
            $branch->save();

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->assignRole(Role::Superadmin);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
