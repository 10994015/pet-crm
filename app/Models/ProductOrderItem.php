<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_order_id', 'product_id', 'name', 'price', 'quantity'])]
class ProductOrderItem extends Model
{
    protected static function booted(): void
    {
        static::creating(function (ProductOrderItem $item) {
            $item->name = $item->name ?: Product::find($item->product_id)?->name;
        });

        static::saved(fn (ProductOrderItem $item) => $item->order->save());
        static::deleted(fn (ProductOrderItem $item) => $item->order?->save());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductOrder::class, 'product_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
