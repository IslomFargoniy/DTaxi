<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\HealthCheck;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn () => app(Settings::class)->get('company.name'),
            'auth' => [
                'user' => $request->user(),
                'roles' => fn () => $request->user()?->getRoleNames() ?? [],
                'permissions' => fn () => $this->permissions($request->user()),
            ],
            'locale' => app()->getLocale(),
            'translations' => fn () => $this->translations(app()->getLocale()),
            // Tizim muammolari — faqat superadmin ko'radi (§10.9)
            'health' => fn () => $request->user()?->isSuperadmin() ? array_values(HealthCheck::problems()) : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return list<string>
     */
    private function permissions(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->isSuperadmin()
            ? ['*']
            : array_values($user->getAllPermissions()->map(fn (Permission $permission): string => $permission->name)->all());
    }

    /**
     * Frontend tarjimalari: lang/{locale}.json
     *
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    }
}
