<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator as ManualPaginator;

class ApiResponse
{
    public static function data(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }

    public static function paginator(LengthAwarePaginator $paginator, ?callable $mapper = null): JsonResponse
    {
        $items = collect($paginator->items());

        if ($mapper) {
            $items = $items->map($mapper)->values();
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function collectionPaginator(Collection $collection, int $page, int $perPage, ?callable $mapper = null): JsonResponse
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $items = $collection->values();
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        if ($mapper) {
            $slice = $slice->map($mapper)->values();
        }

        $paginator = new ManualPaginator(
            $slice,
            $items->count(),
            $perPage,
            $page
        );

        return self::paginator($paginator);
    }
}
