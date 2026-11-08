<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['order_number', 'customer_id', 'discount', 'payment_method', 'paid_at', 'refunded_at', 'notes', 'legacy_id'])]
class ProductOrder extends Model
{
    protected $attributes = ['discount' => 0];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductOrder $order) {
            $order->order_number = $order->order_number
                ?: 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        });

        static::saving(function (ProductOrder $order) {
            $items = $order->exists ? $order->items()->get(['price', 'quantity']) : collect();

            $order->subtotal = (int) $items->sum(fn ($item) => $item->price * $item->quantity);
            $order->total = max(0, $order->subtotal - (int) $order->discount);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductOrderItem::class);
    }
}
