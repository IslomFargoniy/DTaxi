<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mijozlar: qidiruv, izoh, qora ro'yxat.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->string('q')->toString();
        $digits = Client::phoneSearchDigits($q);

        $clients = Client::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$q}%")
                ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))))
            ->when($request->boolean('blacklisted'), fn ($query) => $query->where('is_blacklisted', true))
            ->orderByDesc('orders_count')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/clients', [
            'clients' => $clients->through(fn (Client $c) => $c->only(['id', 'phone', 'name', 'note', 'is_blacklisted', 'orders_count', 'company_id', 'discount_percent'])),
            // Chegirma va kompaniyani faqat narxlarni boshqaruvchi (admin) o'zgartiradi
            'canPrice' => $request->user()?->can('tariffs.manage') ?? false,
            'companies' => Company::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => ['q' => $q, 'blacklisted' => $request->boolean('blacklisted')],
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_blacklisted' => ['boolean'],
            // Rule::exists filial chegarasini bilmaydi — boshqa filial kompaniyasiga biriktirib bo'lmasin
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')
                ->when(! $user->isSuperadmin(), fn ($rule) => $rule->whereIn('branch_id', $user->branchIds()))],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        if (! $request->user()?->can('tariffs.manage')) {
            unset($data['company_id'], $data['discount_percent']);
        }

        $client->update($data);

        return back();
    }
}
