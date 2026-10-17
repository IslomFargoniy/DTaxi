<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Firmaning tashqi xizmatlari kalitlari (reja §0). Faqat superadmin.
 */
class IntegrationController extends Controller
{
    public function index(): Response
    {
        $saved = Integration::query()->get()->keyBy(fn (Integration $i) => $i->provider->value);

        return Inertia::render('admin/integrations', [
            'integrations' => collect(IntegrationProvider::cases())->map(fn (IntegrationProvider $provider) => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'fields' => $provider->fields(),
                'is_enabled' => (bool) $saved->get($provider->value)?->is_enabled,
                'values' => $saved->get($provider->value)?->maskedCredentials() ?? [],
            ])->all(),
        ]);
    }

    public function update(Request $request, IntegrationProvider $provider): RedirectResponse
    {
        $rules = ['is_enabled' => ['required', 'boolean']];
        foreach ($provider->fields() as $field) {
            $rules["credentials.{$field['key']}"] = ['nullable', 'string', 'max:20000'];
            if (isset($field['options'])) {
                $rules["credentials.{$field['key']}"][] = Rule::in($field['options']);
            }
        }
        $data = $request->validate($rules);

        $integration = Integration::for($provider);
        $credentials = $integration->credentials ?? [];

        foreach ($provider->fields() as $field) {
            $value = $data['credentials'][$field['key']] ?? null;
            // Maxfiy maydon bo'sh qoldirilsa — eski qiymat saqlanadi
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
            }
            $credentials[$field['key']] = $value;
        }

        $integration->fill(['is_enabled' => $data['is_enabled'], 'credentials' => $credentials])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Integration saved.')]);

        return to_route('admin.integrations.index');
    }
}
