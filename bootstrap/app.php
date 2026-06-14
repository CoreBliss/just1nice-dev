<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return true;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'message' => 'Akses ditolak.',
            ], 403);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return response()->json([
                'message' => 'Data atau endpoint tidak ditemukan.',
            ], 404);
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            return response()->json([
                'message' => 'Operasi database gagal.',
                'error' => config('app.debug') ? [
                    'type' => get_class($e),
                    'detail' => $e->getMessage(),
                    'sql_state' => $e->errorInfo[0] ?? null,
                    'driver_code' => $e->errorInfo[1] ?? null,
                ] : null,
            ], 500);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'message' => $status === 500
                    ? 'Terjadi kesalahan pada server.'
                    : ($e->getMessage() ?: 'Request gagal.'),
                'error' => config('app.debug') ? [
                    'type' => get_class($e),
                    'detail' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ] : null,
            ], $status);
        });
    })
    ->create();
