<?php

namespace App\Models;

use App\Enums\StockReason;
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
            $item->lockProduct();
        });

        static::updating(function (ProductOrderItem $item) {
            if ($item->isDirty('product_id')) {
                $item->lockProduct();
            }
        });

        static::created(fn (ProductOrderItem $item) => $item->product?->moveStock(-$item->quantity, StockReason::Sale, $item->order));
        static::updated(fn (ProductOrderItem $item) => $item->correctStock());
        static::deleted(fn (ProductOrderItem $item) => $item->product?->moveStock((int) $item->quantity, StockReason::OrderCorrection, $item->order));

        static::saved(fn (ProductOrderItem $item) => $item->order->save());
        static::deleted(fn (ProductOrderItem $item) => $item->order?->save());
    }

    /**
     * Saving this line makes MySQL take a shared lock on the product for the foreign key, and
     * taking stock then needs an exclusive one. Two sales at once would each wait for the
     * other and one would fail with a deadlock, so the exclusive lock comes first.
     */
    private function lockProduct(): void
    {
        if ($this->product_id) {
            Product::whereKey($this->product_id)->lockForUpdate()->value('id');
        }
    }

    /** After an edit: what the old line took goes back, and the new line takes its share. */
    private function correctStock(): void
    {
        if (! $this->wasChanged(['product_id', 'quantity'])) {
            return;
        }

        Product::find($this->getRawOriginal('product_id'))?->moveStock((int) $this->getRawOriginal('quantity'), StockReason::OrderCorrection, $this->order);
        Product::find($this->product_id)?->moveStock(-$this->quantity, StockReason::OrderCorrection, $this->order);
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
