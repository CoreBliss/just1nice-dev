<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $suppliers = Supplier::query()
            ->with('supplierProducts.product')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate((int) ($validated['perPage'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return ApiResponse::paginator($suppliers, fn(Supplier $supplier) => $this->payload($supplier));
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:25'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $q = mb_strtolower($validated['q']);

        $suppliers = Supplier::query()
            ->where('user_id', $request->user()->id)
            ->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 8))
            ->get()
            ->map(fn(Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'detail' => $supplier->detail,
                'leadTimeDays' => $supplier->lead_time_days,
                'products' => [],
            ]);

        return ApiResponse::data($suppliers);
    }

    public function products(Request $request, Supplier $supplier)
    {
        abort_if($supplier->user_id !== $request->user()->id, 404, 'Supplier tidak ditemukan.');

        $products = SupplierProduct::query()
            ->with('product')
            ->where('supplier_id', $supplier->id)
            ->get()
            ->map(fn(SupplierProduct $item) => [
                'productId' => $item->product_id,
                'productCode' => $item->product->code,
                'productName' => $item->product->name,
                'purchasePrice' => $item->purchase_price,
            ]);

        return ApiResponse::data($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:25'],
            'detail' => ['nullable', 'string', 'max:128'],
            'leadTimeDays' => ['required', 'integer', 'min:1', 'max:365'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.productId' => ['required', 'integer', 'exists:products,id'],
            'products.*.purchasePrice' => ['required', 'integer', 'min:0'],
        ]);

        $supplier = DB::transaction(function () use ($request, $validated) {
            $supplier = Supplier::query()->create([
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'detail' => $validated['detail'] ?? null,
                'lead_time_days' => $validated['leadTimeDays'],
            ]);

            foreach ($validated['products'] as $item) {
                $product = Product::query()
                    ->where('user_id', $request->user()->id)
                    ->findOrFail($item['productId']);

                SupplierProduct::query()->create([
                    'supplier_id' => $supplier->id,
                    'product_id' => $product->id,
                    'purchase_price' => $item['purchasePrice'],
                ]);
            }

            return $supplier->load('supplierProducts.product');
        });

        return ApiResponse::data($this->payload($supplier), 'Supplier berhasil ditambahkan.', 201);
    }

    private function payload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'detail' => $supplier->detail,
            'leadTimeDays' => $supplier->lead_time_days,
            'products' => $supplier->supplierProducts->map(fn(SupplierProduct $item) => [
                'productId' => $item->product_id,
                'productCode' => $item->product->code,
                'productName' => $item->product->name,
                'purchasePrice' => $item->purchase_price,
            ])->values(),
        ];
    }
}
