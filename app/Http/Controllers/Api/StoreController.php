<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function show(Request $request)
    {
        $store = $request->user()->store;

        abort_if(! $store, 404, 'Data toko tidak ditemukan.');

        return ApiResponse::data([
            'id' => $store->id,
            'name' => $store->name,
            'email' => $request->user()->email,
            'motto' => $store->motto,
            'photoUrl' => $store->photo_url,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'motto' => ['nullable', 'string', 'max:120'],
        ]);

        $store = $request->user()->store;
        abort_if(! $store, 404, 'Data toko tidak ditemukan.');

        $store->update([
            'motto' => $validated['motto'] ?? null,
        ]);

        return $this->show($request);
    }

    public function photo(Request $request)
    {
        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        $store = $request->user()->store;
        abort_if(! $store, 404, 'Data toko tidak ditemukan.');

        if ($store->photo_path) {
            Storage::disk('public')->delete($store->photo_path);
        }

        $path = $validated['photo']->store('stores', 'public');

        $store->update([
            'photo_path' => $path,
        ]);

        return $this->show($request);
    }
}
