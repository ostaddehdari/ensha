<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\Client;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $this->check($request);
        $actor = $request->user();
        $centreId = $actor->isSuperAdmin() ? $request->integer('centre_id') : (int) $actor->centre_id;
        $clients = Client::query()->visibleTo($actor)->whereNull('merged_into_id')
            ->when($centreId, fn (Builder $query) => $query->where('centre_id', $centreId))
            ->with(['user', 'centre', 'externalIdentities'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = addcslashes(trim((string) $request->input('q')), '%_\\');
                $query->where(function (Builder $scope) use ($term) {
                    $scope->where('client_code', 'like', "%{$term}%")
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%")
                            ->orWhere('national_id', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $centres = $actor->isSuperAdmin() ? Centre::where('is_active', true)->orderBy('name')->get() : collect();

        return view('clients.index', compact('clients', 'centres', 'centreId'));
    }

    public function create(Request $request)
    {
        $this->check($request, true);
        $actor = $request->user();
        $centres = $actor->isSuperAdmin() ? Centre::where('is_active', true)->orderBy('name')->get() : Centre::whereKey($actor->centre_id)->get();
        $users = User::query()->whereDoesntHave('client')->where('status', 'active')
            ->where(function (Builder $query) { $query->where('role', 'client')->orWhereHas('assignedRole', fn (Builder $role) => $role->where('slug', 'client')); })
            ->when(! $actor->isSuperAdmin(), fn (Builder $query) => $query->where('centre_id', $actor->centre_id))
            ->orderBy('last_name')->orderBy('first_name')->get();

        return view('clients.form', ['client' => new Client(), 'users' => $users, 'centres' => $centres]);
    }

    public function store(Request $request)
    {
        $this->check($request, true);
        $data = $this->validated($request);
        $actor = $request->user();
        $centreId = $actor->isSuperAdmin() ? (int) $data['centre_id'] : (int) $actor->centre_id;
        abort_unless($centreId && Centre::whereKey($centreId)->exists(), 422);
        $user = User::whereKey($data['user_id'])->whereDoesntHave('client')->firstOrFail();
        $client = DB::transaction(function () use ($data, $centreId, $user, $actor) {
            $client = Client::create([
                ...$data,
                'centre_id' => $centreId,
                'client_code' => $this->nextCode($centreId),
                'created_by' => $actor->id,
            ]);
            $this->syncIdentity($client, $data);
            return $client;
        });
        Audit::record('ایجاد پرونده پایه مراجع', $request, 'info', ['client_id' => $client->id], $client, 'client.created');

        return redirect()->route('clients.show', $client)->with('success', 'پرونده پایه مراجع ایجاد شد.');
    }

    public function show(Request $request, Client $client)
    {
        $this->check($request);
        $this->visible($request, $client);
        $client->load([
            'user', 'centre', 'externalIdentities', 'cases',
            'intakes' => fn ($query) => $query->latest('id'),
            'guardians' => fn ($query) => $query->orderByDesc('is_primary')->latest('id'),
            'emergencyContacts' => fn ($query) => $query->orderBy('priority'),
            'consents' => fn ($query) => $query->latest('id'),
            'privateFiles' => fn ($query) => $query->latest('id'),
            'mergedInto.user', 'mergedSources.user',
        ]);
        return view('clients.show', compact('client'));
    }

    public function edit(Request $request, Client $client)
    {
        $this->check($request, true);
        $this->visible($request, $client);
        abort_if($client->merged_into_id !== null, 409, 'پرونده ادغام‌شده قابل ویرایش نیست.');
        $client->load(['user', 'externalIdentities']);
        $actor = $request->user();
        $centres = $actor->isSuperAdmin() ? Centre::where('is_active', true)->orderBy('name')->get() : Centre::whereKey($actor->centre_id)->get();
        return view('clients.form', compact('client', 'centres'));
    }

    public function update(Request $request, Client $client)
    {
        $this->check($request, true);
        $this->visible($request, $client);
        abort_if($client->merged_into_id !== null, 409, 'پرونده ادغام‌شده قابل ویرایش نیست.');
        $data = $this->validated($request, $client);
        $actor = $request->user();
        $centreId = $actor->isSuperAdmin() ? (int) $data['centre_id'] : (int) $actor->centre_id;
        DB::transaction(function () use ($client, $data, $centreId) {
            $client->update([...$data, 'centre_id' => $centreId]);
            $this->syncIdentity($client, $data);
        });
        Audit::record('ویرایش پرونده پایه مراجع', $request, 'info', ['client_id' => $client->id], $client, 'client.updated');
        return redirect()->route('clients.show', $client)->with('success', 'پرونده پایه مراجع به‌روزرسانی شد.');
    }

    private function syncIdentity(Client $client, array $data): void
    {
        if (blank($data['provider'] ?? null) || blank($data['external_id'] ?? null)) {
            return;
        }
        $client->externalIdentities()->updateOrCreate(['provider' => $data['provider']], [
            'external_id' => $data['external_id'],
            'external_username' => $data['external_username'] ?? null,
            'external_email' => $data['external_email'] ?? null,
            'linked_at' => now(),
        ]);
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'centre_id' => ['required', 'integer', Rule::exists('centres', 'id')],
            'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'preferred_contact' => ['nullable', Rule::in(['phone', 'sms', 'email', 'whatsapp'])],
            'referral_source' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'external_username' => ['nullable', 'string', 'max:190'],
            'external_email' => ['nullable', 'email', 'max:190'],
        ]);
        return $data;
    }

    private function nextCode(int $centreId): string
    {
        $next = (int) Client::withTrashed()->where('centre_id', $centreId)->lockForUpdate()->count() + 1;
        do { $code = 'CL-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT); }
        while (Client::withTrashed()->where('centre_id', $centreId)->where('client_code', $code)->exists());
        return $code;
    }

    private function visible(Request $request, Client $client): void
    {
        abort_unless($request->user()->isSuperAdmin() || $client->centre_id === $request->user()->centre_id, 404);
    }

    private function check(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()->hasPermission($manage ? 'clients.manage' : 'clients.view'), 403);
    }
}
