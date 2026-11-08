<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\StockReason;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['order_number', 'customer_id', 'discount', 'payment_method', 'paid_at', 'refunded_at', 'notes', 'legacy_id'])]
class ProductOrder extends Model
{
    use RecordsActivity;

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

    /**
     * Takes the whole order out of revenue, once. Goods only go back on the shelf when they
     * can be sold again, which is the shop's call.
     */
    public function refund(bool $returnToStock): void
    {
        DB::transaction(function () use ($returnToStock) {
            if (self::whereKey($this->getKey())->lockForUpdate()->value('refunded_at') !== null) {
                return;
            }

            $this->update(['refunded_at' => now()]);

            if ($returnToStock) {
                foreach ($this->items()->with('product')->get() as $item) {
                    $item->product?->moveStock((int) $item->quantity, StockReason::Refund, $this);
                }
            }
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
