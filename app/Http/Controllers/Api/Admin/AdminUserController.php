<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with('store')->latest();

        if (! empty($validated['q'])) {
            $q = mb_strtolower($validated['q']);

            $query->where(function ($builder) use ($q): void {
                $builder->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(role) LIKE ?', ["%{$q}%"]);
            });
        }

        $users = $query->paginate((int) ($validated['perPage'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return ApiResponse::paginator($users, fn(User $user) => $this->payload($user));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'storeName' => ['required_if:role,user', 'nullable', 'string', 'max:80'],
            'motto' => ['nullable', 'string', 'max:120'],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'created_by' => $request->user()->id,
            ]);

            if ($user->role === User::ROLE_USER) {
                Store::query()->create([
                    'user_id' => $user->id,
                    'name' => $validated['storeName'],
                    'motto' => $validated['motto'] ?? 'Kontrol penjualan sparepart harian',
                ]);
            }

            return $user->load('store');
        });

        return ApiResponse::data($this->payload($user), 'User berhasil dibuat.', 201);
    }

    public function show(User $user)
    {
        return ApiResponse::data($this->payload($user->load('store')));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'storeName' => ['required_if:role,user', 'nullable', 'string', 'max:80'],
            'motto' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($validated, $user): void {
            $data = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
            ];

            if (! empty($validated['password'])) {
                $data['password'] = $validated['password'];
            }

            $user->update($data);

            if ($user->role === User::ROLE_USER) {
                Store::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'name' => $validated['storeName'],
                        'motto' => $validated['motto'] ?? null,
                    ]
                );
            } else {
                $user->store()?->delete();
            }
        });

        return ApiResponse::data($this->payload($user->refresh()->load('store')), 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return ApiResponse::message('Admin tidak boleh menghapus akun sendiri.', 422);
        }

        $user->delete();

        return ApiResponse::message('User berhasil dihapus.');
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'motto' => $user->store->motto,
                'photoUrl' => $user->store->photo_url,
            ] : null,
            'createdAt' => $user->created_at?->toISOString(),
        ];
    }
}
