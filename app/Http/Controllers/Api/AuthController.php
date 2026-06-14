<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'storeName' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with('store')
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($user->role === User::ROLE_USER) {
            $storeName = trim((string) ($validated['storeName'] ?? ''));

            if (! $user->store || mb_strtolower($user->store->name) !== mb_strtolower($storeName)) {
                throw ValidationException::withMessages([
                    'storeName' => ['Nama toko tidak sesuai dengan akun ini.'],
                ]);
            }
        }

        $user->tokens()
            ->where('name', 'softie-client')
            ->delete();

        $token = $user->createToken('softie-client', [$user->role])->plainTextToken;

        return ApiResponse::data([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'email' => $user->email,
                'motto' => $user->store->motto,
                'photoUrl' => $user->store->photo_url,
            ] : null,
        ], 'Login berhasil.');
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::message('Logout berhasil.');
    }
}
