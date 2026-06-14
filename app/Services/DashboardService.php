<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RestockOrder;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    public function summary(int $userId, string $startDate, string $endDate, string $groupBy): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        if ($end->isFuture()) {
            $end = now()->endOfDay();
        }

        if ($start->greaterThan($end)) {
            return $this->emptySummary();
        }

        $salesTotal = (int) Sale::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        $days = max($start->diffInDays($end) + 1, 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        $previousTotal = (int) Sale::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->sum('total');

        $salesChangePct = $previousTotal > 0
            ? round((($salesTotal - $previousTotal) / $previousTotal) * 100, 2)
            : null;

        $profit = $this->profitQuery($userId, $start, $end)->first();

        $netProfit = (int) ($profit->profit ?? 0);
        $revenue = (int) ($profit->revenue ?? 0);
        $netMarginPct = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : null;

        $topProduct = SaleItem::query()
            ->select('product_id', 'product_name_snapshot', DB::raw('SUM(quantity) as sold_qty'))
            ->whereHas('sale', fn($query) => $query
                ->where('user_id', $userId)
                ->whereBetween('created_at', [$start, $end]))
            ->groupBy('product_id', 'product_name_snapshot')
            ->orderByDesc('sold_qty')
            ->first();

        $products = Product::query()->where('user_id', $userId)->get();

        $ropAlerts = $products
            ->map(fn(Product $product) => $this->inventoryService->productPayload($product))
            ->filter(fn(array $product) => $product['status'] !== 'Aman')
            ->values();

        $notes = [];

        if ($topProduct) {
            $notes[] = $topProduct->product_name_snapshot . ' menjadi kontributor penjualan tertinggi pada periode ini.';
        }

        if ($ropAlerts->isNotEmpty()) {
            $notes[] = $ropAlerts->count() . ' SKU mendekati atau berada di bawah batas ROP dan perlu dijadwalkan restock.';
        }

        return [
            'cards' => [
                'salesTotal' => $salesTotal,
                'salesChangePct' => $salesChangePct,
                'netProfit' => $netProfit,
                'netMarginPct' => $netMarginPct,
                'topProduct' => $topProduct ? [
                    'id' => $topProduct->product_id,
                    'name' => $topProduct->product_name_snapshot,
                    'soldQty' => (int) $topProduct->sold_qty,
                ] : null,
                'stockAlertCount' => $ropAlerts->count(),
            ],
            'chart' => $this->salesChart($userId, $start, $end, $groupBy),
            'quickSummary' => [
                'finishedTransactions' => Sale::query()->where('user_id', $userId)->whereBetween('created_at', [$start, $end])->count(),
                'activeProducts' => Product::query()->where('user_id', $userId)->count(),
                'activeSuppliers' => DB::table('suppliers')->where('user_id', $userId)->count(),
            ],
            'notes' => $notes,
            'ropAlerts' => $ropAlerts->map(fn(array $item) => [
                'productId' => $item['id'],
                'productName' => $item['name'],
                'stockActual' => $item['stockActual'],
                'rop' => $item['rop'],
                'status' => $item['status'],
            ])->values(),
        ];
    }

    public function profitDetail(int $userId, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        if ($end->isFuture()) {
            $end = now()->endOfDay();
        }

        $profit = $this->profitQuery($userId, $start, $end)->first();
        $totalProfit = (int) ($profit->profit ?? 0);
        $revenue = (int) ($profit->revenue ?? 0);

        $items = SaleItem::query()
            ->select(
                'sale_items.product_id',
                'sale_items.product_name_snapshot',
                DB::raw('SUM(sale_items.quantity) as sold_qty'),
                DB::raw('ROUND(AVG(sale_items.unit_price)) as avg_selling_price'),
                DB::raw('ROUND(AVG(sale_items.purchase_price_snapshot)) as avg_purchase_price'),
                DB::raw('SUM((sale_items.unit_price - sale_items.purchase_price_snapshot) * sale_items.quantity) as profit'),
                DB::raw('SUM(sale_items.unit_price * sale_items.quantity) as revenue')
            )
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $userId)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id', 'sale_items.product_name_snapshot')
            ->orderByDesc('profit')
            ->get()
            ->map(fn($item) => [
                'productId' => $item->product_id,
                'productName' => $item->product_name_snapshot,
                'soldQty' => (int) $item->sold_qty,
                'avgSellingPrice' => (int) $item->avg_selling_price,
                'avgPurchasePrice' => (int) $item->avg_purchase_price,
                'profit' => (int) $item->profit,
                'marginPct' => ((int) $item->revenue) > 0 ? round(((int) $item->profit / (int) $item->revenue) * 100, 2) : 0,
            ]);

        return [
            'totalProfit' => $totalProfit,
            'marginPct' => $revenue > 0 ? round(($totalProfit / $revenue) * 100, 2) : 0,
            'purchaseHistories' => $this->purchaseHistories($userId, $start, $end),
            'salesHistories' => $this->salesHistories($userId, $start, $end),
            'items' => $items,
            'chart' => $this->profitChart($userId, $start, $end),
        ];
    }

    private function salesChart(int $userId, Carbon $start, Carbon $end, string $groupBy): array
    {
        $rows = Sale::query()
            ->selectRaw($groupBy === 'month'
                ? "TO_CHAR(created_at, 'YYYY-MM') as bucket, SUM(total) as total"
                : "TO_CHAR(created_at, 'YYYY-MM-DD') as bucket, SUM(total) as total")
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('total', 'bucket');

        if ($groupBy === 'month') {
            $period = CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end->copy()->startOfMonth());

            return collect($period)->map(fn(Carbon $date) => [
                'label' => $date->translatedFormat('M Y'),
                'date' => $date->format('Y-m'),
                'total' => (int) ($rows[$date->format('Y-m')] ?? 0),
            ])->values()->all();
        }

        $period = CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay());

        return collect($period)->map(fn(Carbon $date) => [
            'label' => $date->format('d/m'),
            'date' => $date->toDateString(),
            'total' => (int) ($rows[$date->toDateString()] ?? 0),
        ])->values()->all();
    }

    private function profitChart(int $userId, Carbon $start, Carbon $end): array
    {
        $actualStart = $end->copy()->subDays(6)->startOfDay();

        if ($actualStart->lessThan($start)) {
            $actualStart = $start;
        }

        $rows = SaleItem::query()
            ->selectRaw("TO_CHAR(sales.created_at, 'YYYY-MM-DD') as bucket")
            ->selectRaw('SUM((sale_items.unit_price - sale_items.purchase_price_snapshot) * sale_items.quantity) as total')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $userId)
            ->whereBetween('sales.created_at', [$actualStart, $end])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('total', 'bucket');

        $period = CarbonPeriod::create($actualStart, '1 day', $end->copy()->startOfDay());

        return collect($period)->map(fn(Carbon $date) => [
            'label' => $date->format('d/m'),
            'date' => $date->toDateString(),
            'total' => (int) ($rows[$date->toDateString()] ?? 0),
        ])->values()->all();
    }

    private function profitQuery(int $userId, Carbon $start, Carbon $end)
    {
        return SaleItem::query()
            ->selectRaw('SUM((sale_items.unit_price - sale_items.purchase_price_snapshot) * sale_items.quantity) as profit')
            ->selectRaw('SUM(sale_items.unit_price * sale_items.quantity) as revenue')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $userId)
            ->whereBetween('sales.created_at', [$start, $end]);
    }

    private function purchaseHistories(int $userId, Carbon $start, Carbon $end): Collection
    {
        return DB::table('restock_order_items')
            ->join('restock_orders', 'restock_orders.id', '=', 'restock_order_items.restock_order_id')
            ->join('products', 'products.id', '=', 'restock_order_items.product_id')
            ->where('restock_orders.user_id', $userId)
            ->where('restock_orders.status', RestockOrder::STATUS_RECEIVED)
            ->whereBetween('restock_orders.received_at', [$start, $end])
            ->orderByDesc('restock_orders.received_at')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'date' => $row->received_at,
                'productName' => $row->name,
                'quantity' => (int) $row->quantity,
                'unitPurchasePrice' => (int) $row->purchase_price,
                'total' => (int) $row->total,
            ]);
    }

    private function salesHistories(int $userId, Carbon $start, Carbon $end): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $userId)
            ->whereBetween('sales.created_at', [$start, $end])
            ->orderByDesc('sales.created_at')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'date' => $row->created_at,
                'productName' => $row->product_name_snapshot,
                'quantity' => (int) $row->quantity,
                'unitSellingPrice' => (int) $row->unit_price,
                'total' => (int) $row->subtotal,
            ]);
    }

    private function emptySummary(): array
    {
        return [
            'cards' => [
                'salesTotal' => 0,
                'salesChangePct' => null,
                'netProfit' => 0,
                'netMarginPct' => null,
                'topProduct' => null,
                'stockAlertCount' => 0,
            ],
            'chart' => [],
            'quickSummary' => [
                'finishedTransactions' => 0,
                'activeProducts' => 0,
                'activeSuppliers' => 0,
            ],
            'notes' => [],
            'ropAlerts' => [],
        ];
    }
}
