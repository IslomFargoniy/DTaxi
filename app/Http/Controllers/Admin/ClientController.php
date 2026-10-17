<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $digits = preg_replace('/\D/', '', $q);

        $clients = Client::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$q}%")
                ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))))
            ->when($request->boolean('blacklisted'), fn ($query) => $query->where('is_blacklisted', true))
            ->orderByDesc('orders_count')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/clients', [
            'clients' => $clients->through(fn (Client $c) => $c->only(['id', 'phone', 'name', 'note', 'is_blacklisted', 'orders_count'])),
            'filters' => ['q' => $q, 'blacklisted' => $request->boolean('blacklisted')],
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_blacklisted' => ['boolean'],
        ]));

        return back();
    }
}
