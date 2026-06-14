<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unitPrice' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'cashReceived' => ['required', 'integer', 'min:0'],
        ]);

        $receipt = DB::transaction(function () use ($request, $validated) {
            $products = Product::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('id', collect($validated['items'])->pluck('productId'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemsToSave = [];

            foreach ($validated['items'] as $item) {
                $product = $products->get($item['productId']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['Barang tidak ditemukan.'],
                    ]);
                }

                if ($product->stock_actual < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Stok {$product->name} tidak mencukupi."],
                    ]);
                }

                $lineSubtotal = $product->selling_price * $item['quantity'];
                $subtotal += $lineSubtotal;

                $itemsToSave[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->selling_price,
                    'purchase_price_snapshot' => $this->inventoryService->averagePurchaseCost($product, $item['quantity']),
                    'subtotal' => $lineSubtotal,
                ];
            }

            $discount = (int) ($validated['discount'] ?? 0);
            $total = max($subtotal - $discount, 0);

            if ($validated['cashReceived'] < $total) {
                throw ValidationException::withMessages([
                    'cashReceived' => ['Tunai diterima belum mencukupi total tagihan.'],
                ]);
            }

            $sale = Sale::query()->create([
                'user_id' => $request->user()->id,
                'transaction_number' => $this->transactionNumber(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'cash_received' => $validated['cashReceived'],
                'change' => $validated['cashReceived'] - $total,
            ]);

            foreach ($itemsToSave as $item) {
                /** @var Product $product */
                $product = $item['product'];

                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'purchase_price_snapshot' => $item['purchase_price_snapshot'],
                    'subtotal' => $item['subtotal'],
                ]);

                $product->decrement('stock_actual', $item['quantity']);
            }

            return $sale->load('items');
        });

        return ApiResponse::data([
            'id' => $receipt->id,
            'transactionNumber' => $receipt->transaction_number,
            'createdAt' => $receipt->created_at?->toISOString(),
            'subtotal' => $receipt->subtotal,
            'discount' => $receipt->discount,
            'total' => $receipt->total,
            'cashReceived' => $receipt->cash_received,
            'change' => $receipt->change,
            'items' => $receipt->items->map(fn(SaleItem $item) => [
                'productId' => $item->product_id,
                'productName' => $item->product_name_snapshot,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->values(),
        ], 'Transaksi berhasil.', 201);
    }

    private function transactionNumber(): string
    {
        do {
            $number = 'TRX-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (Sale::query()->where('transaction_number', $number)->exists());

        return $number;
    }
}
