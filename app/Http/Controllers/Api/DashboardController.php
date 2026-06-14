<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DashboardService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly InventoryService $inventoryService
    ) {}

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'groupBy' => ['required', 'in:day,month'],
            'chartType' => ['nullable', 'in:bar,line'],
        ]);

        return ApiResponse::data(
            $this->dashboardService->summary(
                $request->user()->id,
                $validated['startDate'],
                $validated['endDate'],
                $validated['groupBy']
            )
        );
    }

    public function salesHistory(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sales = Sale::query()
            ->withCount('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate((int) ($validated['perPage'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return ApiResponse::paginator($sales, fn(Sale $sale) => [
            'id' => $sale->id,
            'transactionNumber' => $sale->transaction_number,
            'createdAt' => $sale->created_at?->toISOString(),
            'total' => $sale->total,
            'cashReceived' => $sale->cash_received,
            'change' => $sale->change,
            'itemsCount' => $sale->items_count,
        ]);
    }

    public function profitDetail(Request $request)
    {
        $validated = $request->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'groupBy' => ['nullable', 'in:day,month'],
            'page' => ['nullable', 'integer'],
            'perPage' => ['nullable', 'integer'],
        ]);

        return ApiResponse::data(
            $this->dashboardService->profitDetail(
                $request->user()->id,
                $validated['startDate'],
                $validated['endDate']
            )
        );
    }

    public function topProducts(Request $request)
    {
        $products = Product::query()
            ->where('user_id', $request->user()->id)
            ->withSum('saleItems as sold_qty', 'quantity')
            ->orderByDesc('sold_qty')
            ->paginate((int) ($request->integer('perPage') ?: 10));

        return ApiResponse::paginator($products, fn(Product $product) => $this->inventoryService->productPayload($product));
    }

    public function stockAlerts(Request $request)
    {
        $products = Product::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->filter(fn(Product $product) => $this->inventoryService->stockStatus($product) !== 'Aman')
            ->values();

        return ApiResponse::collectionPaginator(
            $products,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('perPage') ?: 10),
            fn(Product $product) => $this->inventoryService->productPayload($product)
        );
    }
}
