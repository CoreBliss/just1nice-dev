<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapUserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RestockOrderController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return new JsonResponse([
        'status' => 'ok',
    ]);
});

Route::post('/bootstrap/users', [BootstrapUserController::class, 'store']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware('role:user')->group(function (): void {
        Route::get('/me/store', [StoreController::class, 'show']);
        Route::patch('/me/store', [StoreController::class, 'update']);
        Route::post('/me/store/photo', [StoreController::class, 'photo']);

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/sales/history', [DashboardController::class, 'salesHistory']);
        Route::get('/profit/detail', [DashboardController::class, 'profitDetail']);
        Route::get('/top-products', [DashboardController::class, 'topProducts']);
        Route::get('/stock-alerts', [DashboardController::class, 'stockAlerts']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/search', [ProductController::class, 'search']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/search', [SupplierController::class, 'search']);
        Route::get('/suppliers/{supplier}/products', [SupplierController::class, 'products']);
        Route::post('/suppliers', [SupplierController::class, 'store']);

        Route::get('/restock-orders/pending', [RestockOrderController::class, 'pending']);
        Route::post('/restock-orders', [RestockOrderController::class, 'store']);
        Route::patch('/restock-orders/{restockOrder}/receive', [RestockOrderController::class, 'receive']);
        Route::delete('/restock-orders/{restockOrder}', [RestockOrderController::class, 'destroy']);

        Route::post('/pos/checkout', [PosController::class, 'checkout']);
    });

    Route::prefix('admin')->middleware('role:admin')->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
    });
});
