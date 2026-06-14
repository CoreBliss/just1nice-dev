<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BootstrapUserController extends Controller
{
    public function store(Request $request)
    {
        if (User::query()->exists() && ! config('softie.bootstrap_open')) {
            return ApiResponse::message('Bootstrap user sudah ditutup.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'storeName' => ['required_if:role,user', 'nullable', 'string', 'max:80'],
            'motto' => ['nullable', 'string', 'max:120'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
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

        return ApiResponse::data([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store' => $user->store,
        ], 'Akun awal berhasil dibuat.', 201);
    }
}
