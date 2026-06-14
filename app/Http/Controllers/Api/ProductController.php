<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->where('user_id', $request->user()->id);

        if (! empty($validated['q'])) {
            $q = mb_strtolower($validated['q']);

            $query->where(function ($builder) use ($q): void {
                $builder->whereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
            });
        }

        match ($validated['filter'] ?? 'all') {
            'stock_desc' => $query->orderByDesc('stock_actual'),
            'stock_asc' => $query->orderBy('stock_actual'),
            'restock_newest' => $query->orderByDesc('last_restock_date'),
            'restock_oldest' => $query->orderBy('last_restock_date'),
            default => $query->latest(),
        };

        $products = $query->get();

        if (in_array($validated['filter'] ?? '', ['status_safe', 'status_restock', 'status_critical'], true)) {
            $expected = match ($validated['filter']) {
                'status_safe' => 'Aman',
                'status_restock' => 'Perlu Restock',
                'status_critical' => 'Kritis',
            };

            $products = $products->filter(
                fn(Product $product) => $this->inventoryService->stockStatus($product) === $expected
            )->values();
        }

        return ApiResponse::collectionPaginator(
            $products,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['perPage'] ?? 10),
            fn(Product $product) => $this->inventoryService->productPayload($product)
        );
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $q = mb_strtolower($validated['q']);

        $products = Product::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($builder) use ($q): void {
                $builder->whereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
            })
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 8))
            ->get()
            ->map(fn(Product $product) => $this->inventoryService->productPayload($product))
            ->values();

        return ApiResponse::data($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('products', 'code')->where('user_id', $request->user()->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'stockActual' => ['required', 'integer', 'min:0'],
            'safetyStock' => ['required', 'integer', 'min:0'],
            'sellingPrice' => ['required', 'integer', 'min:0'],
            'lastRestockDate' => ['required', 'date'],
        ]);

        $product = Product::query()->create([
            'user_id' => $request->user()->id,
            'code' => $validated['code'] ?: $this->generateCode($request->user()->id),
            'name' => $validated['name'],
            'stock_actual' => $validated['stockActual'],
            'safety_stock' => $validated['safetyStock'],
            'selling_price' => $validated['sellingPrice'],
            'last_restock_date' => $validated['lastRestockDate'],
        ]);

        return ApiResponse::data($this->inventoryService->productPayload($product), 'Barang berhasil ditambahkan.', 201);
    }

    public function update(Request $request, Product $product)
    {
        abort_if($product->user_id !== $request->user()->id, 404, 'Barang tidak ditemukan.');

        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('products', 'code')
                    ->where('user_id', $request->user()->id)
                    ->ignore($product->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'stockActual' => ['required', 'integer', 'min:0'],
            'safetyStock' => ['required', 'integer', 'min:0'],
            'sellingPrice' => ['required', 'integer', 'min:0'],
            'lastRestockDate' => ['required', 'date'],
        ]);

        $product->update([
            'code' => $validated['code'] ?: $product->code,
            'name' => $validated['name'],
            'stock_actual' => $validated['stockActual'],
            'safety_stock' => $validated['safetyStock'],
            'selling_price' => $validated['sellingPrice'],
            'last_restock_date' => $validated['lastRestockDate'],
        ]);

        return ApiResponse::data($this->inventoryService->productPayload($product->refresh()), 'Barang berhasil diperbarui.');
    }

    public function destroy(Request $request, Product $product)
    {
        abort_if($product->user_id !== $request->user()->id, 404, 'Barang tidak ditemukan.');

        $product->delete();

        return ApiResponse::message('Barang berhasil dihapus.');
    }

    private function generateCode(int $userId): string
    {
        do {
            $code = 'SP-' . strtoupper(Str::random(6));
        } while (Product::query()->where('user_id', $userId)->where('code', $code)->exists());

        return $code;
    }
}
