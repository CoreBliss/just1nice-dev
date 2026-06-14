<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Just1Nice API is running.',
        'app' => config('app.name'),
        'health' => url('/api/health'),
        'time' => now()->toISOString(),
    ]);
});

Route::fallback(function () {
    return response()->json([
        'message' => 'Endpoint tidak ditemukan.',
    ], 404);
});