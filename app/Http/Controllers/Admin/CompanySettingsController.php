<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Butun firma uchun umumiy sozlamalar (filial o'zinikini bermasa shu qiymatlar ishlatiladi).
 */
class CompanySettingsController extends Controller
{
    /**
     * Panelda tahrirlanadigan kalitlar va ularning validatsiyasi.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'company.name' => ['required', 'string', 'max:100'],
            'company.default_locale' => ['required', Rule::in(SetLocale::LOCALES)],
            'drivers.commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'drivers.min_balance' => ['required', 'integer', 'min:0'],
            'tariffs.rounding_step' => ['required', 'integer', Rule::in([1, 100, 500, 1000])],
            'calls.recordings_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function edit(Settings $settings): Response
    {
        return Inertia::render('admin/company-settings', [
            'settings' => collect(array_keys($this->rules()))
                ->mapWithKeys(fn (string $key) => [$key => $settings->get($key)])
                ->all(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        // Nuqtali kalitlar formada `company__name` ko'rinishida keladi
        $input = collect($request->all())->mapWithKeys(fn ($v, $k) => [str_replace('__', '.', $k) => $v]);
        $data = Arr::dot(validator(Arr::undot($input->all()), $this->rules())->validate());

        foreach ($data as $key => $value) {
            $settings->set($key, is_numeric($value) ? $value + 0 : $value);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('admin.company-settings.edit');
    }
}
