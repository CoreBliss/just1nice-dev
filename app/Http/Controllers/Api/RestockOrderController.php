<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RestockOrder;
use App\Models\RestockOrderItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestockOrderController extends Controller
{
    public function pending(Request $request)
    {
        $orders = RestockOrder::query()
            ->with(['supplier', 'items.product'])
            ->where('user_id', $request->user()->id)
            ->where('status', RestockOrder::STATUS_PENDING)
            ->latest()
            ->paginate(50);

        return ApiResponse::paginator($orders, fn(RestockOrder $order) => $this->payload($order));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplierId' => ['required', 'integer', 'exists:suppliers,id'],
            'restockDate' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:256'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.purchasePrice' => ['required', 'integer', 'min:0'],
        ]);

        $supplier = Supplier::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($validated['supplierId']);

        $order = DB::transaction(function () use ($request, $validated, $supplier) {
            $total = collect($validated['items'])
                ->sum(fn(array $item) => $item['quantity'] * $item['purchasePrice']);

            $order = RestockOrder::query()->create([
                'user_id' => $request->user()->id,
                'supplier_id' => $supplier->id,
                'restock_date' => $validated['restockDate'],
                'note' => $validated['note'] ?? null,
                'total' => $total,
                'status' => RestockOrder::STATUS_PENDING,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::query()
                    ->where('user_id', $request->user()->id)
                    ->findOrFail($item['productId']);

                SupplierProduct::query()->updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'purchase_price' => $item['purchasePrice'],
                    ]
                );

                RestockOrderItem::query()->create([
                    'restock_order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'purchase_price' => $item['purchasePrice'],
                    'total' => $item['quantity'] * $item['purchasePrice'],
                ]);
            }

            return $order->load(['supplier', 'items.product']);
        });

        return ApiResponse::data($this->payload($order), 'Pesanan restock berhasil dibuat.', 201);
    }

    public function receive(Request $request, RestockOrder $restockOrder)
    {
        abort_if($restockOrder->user_id !== $request->user()->id, 404, 'Pesanan tidak ditemukan.');
        abort_if($restockOrder->status !== RestockOrder::STATUS_PENDING, 422, 'Pesanan ini tidak dalam status pending.');

        $order = DB::transaction(function () use ($restockOrder) {
            $restockOrder->load('items.product');

            foreach ($restockOrder->items as $item) {
                $item->product->increment('stock_actual', $item->quantity);
                $item->product->update([
                    'last_restock_date' => $restockOrder->restock_date,
                ]);
            }

            $restockOrder->update([
                'status' => RestockOrder::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            return $restockOrder->refresh()->load(['supplier', 'items.product']);
        });

        return ApiResponse::data($this->payload($order), 'Barang diterima dan stok berhasil diperbarui.');
    }

    public function destroy(Request $request, RestockOrder $restockOrder)
    {
        abort_if($restockOrder->user_id !== $request->user()->id, 404, 'Pesanan tidak ditemukan.');
        abort_if($restockOrder->status !== RestockOrder::STATUS_PENDING, 422, 'Hanya pesanan pending yang dapat dibatalkan.');

        $restockOrder->update([
            'status' => RestockOrder::STATUS_CANCELLED,
        ]);

        return ApiResponse::message('Pesanan restock berhasil dibatalkan.');
    }

    private function payload(RestockOrder $order): array
    {
        return [
            'id' => $order->id,
            'supplierId' => $order->supplier_id,
            'supplierName' => $order->supplier->name,
            'restockDate' => $order->restock_date?->toDateString(),
            'note' => $order->note,
            'total' => $order->total,
            'status' => $order->status,
            'items' => $order->items->map(fn(RestockOrderItem $item) => [
                'productId' => $item->product_id,
                'productName' => $item->product->name,
                'quantity' => $item->quantity,
                'purchasePrice' => $item->purchase_price,
                'total' => $item->total,
            ])->values(),
        ];
    }
}
