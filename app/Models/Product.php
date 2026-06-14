<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'name',
        'stock_actual',
        'safety_stock',
        'selling_price',
        'last_restock_date',
    ];

    protected function casts(): array
    {
        return [
            'stock_actual' => 'integer',
            'safety_stock' => 'integer',
            'selling_price' => 'integer',
            'last_restock_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function restockItems(): HasMany
    {
        return $this->hasMany(RestockOrderItem::class);
    }
}
