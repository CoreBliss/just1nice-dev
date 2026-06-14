<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RestockOrder;
use App\Models\RestockOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function averageDailySales(Product $product, int $days = 30): float
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $qty = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $product->user_id)
            ->where('sale_items.product_id', $product->id)
            ->where('sales.created_at', '>=', $start)
            ->sum('sale_items.quantity');

        return round($qty / max($days, 1), 2);
    }

    public function leadTimeDays(Product $product): int
    {
        $leadTime = DB::table('supplier_products')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_products.supplier_id')
            ->where('supplier_products.product_id', $product->id)
            ->where('suppliers.user_id', $product->user_id)
            ->max('suppliers.lead_time_days');

        return (int) ($leadTime ?: 0);
    }

    public function rop(Product $product): int
    {
        $averageDailySales = $this->averageDailySales($product);
        $leadTime = $this->leadTimeDays($product);

        return (int) ceil(($averageDailySales * $leadTime) + $product->safety_stock);
    }

    public function stockStatus(Product $product): string
    {
        $rop = $this->rop($product);

        if ($product->stock_actual <= $product->safety_stock) {
            return 'Kritis';
        }

        if ($product->stock_actual <= $rop) {
            return 'Perlu Restock';
        }

        return 'Aman';
    }

    public function productPayload(Product $product): array
    {
        $avgDailySales = $this->averageDailySales($product);
        $leadTime = $this->leadTimeDays($product);
        $rop = (int) ceil(($avgDailySales * $leadTime) + $product->safety_stock);

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'stockActual' => $product->stock_actual,
            'safetyStock' => $product->safety_stock,
            'sellingPrice' => $product->selling_price,
            'lastRestockDate' => $product->last_restock_date?->toDateString(),
            'avgDailySales' => $avgDailySales,
            'leadTimeDays' => $leadTime,
            'rop' => $rop,
            'status' => $this->statusFromNumbers($product->stock_actual, $product->safety_stock, $rop),
        ];
    }

    public function statusFromNumbers(int $stockActual, int $safetyStock, int $rop): string
    {
        if ($stockActual <= $safetyStock) {
            return 'Kritis';
        }

        if ($stockActual <= $rop) {
            return 'Perlu Restock';
        }

        return 'Aman';
    }

    public function averagePurchaseCost(Product $product, int $quantity): int
    {
        $remaining = max($quantity, 1);
        $totalCost = 0;
        $takenQty = 0;

        $items = RestockOrderItem::query()
            ->select('restock_order_items.*')
            ->join('restock_orders', 'restock_orders.id', '=', 'restock_order_items.restock_order_id')
            ->where('restock_orders.user_id', $product->user_id)
            ->where('restock_orders.status', RestockOrder::STATUS_RECEIVED)
            ->where('restock_order_items.product_id', $product->id)
            ->orderByDesc('restock_orders.received_at')
            ->orderByDesc('restock_orders.id')
            ->get();

        foreach ($items as $item) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $item->quantity);
            $totalCost += $take * $item->purchase_price;
            $takenQty += $take;
            $remaining -= $take;
        }

        if ($takenQty > 0) {
            return (int) round($totalCost / $takenQty);
        }

        $fallback = DB::table('supplier_products')
            ->where('product_id', $product->id)
            ->orderByDesc('updated_at')
            ->value('purchase_price');

        return (int) ($fallback ?: 0);
    }
}
