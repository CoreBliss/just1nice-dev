<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockOrderItem extends Model
{
    protected $fillable = [
        'restock_order_id',
        'product_id',
        'quantity',
        'purchase_price',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'purchase_price' => 'integer',
            'total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestockOrder::class, 'restock_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}