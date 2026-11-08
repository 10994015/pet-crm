<?php

namespace App\Models;

use App\Enums\StockReason;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'price', 'is_active', 'sort_order', 'stock', 'low_stock_at'])]
class Product extends Model
{
    use RecordsActivity;

    protected $attributes = ['is_active' => true, 'low_stock_at' => 3];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'stock' => 'integer', 'low_stock_at' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->sort_order = $product->sort_order ?: (int) self::max('sort_order') + 1;
        });

        static::created(function (Product $product) {
            if ($product->stock !== null) {
                $product->writeMovement(StockReason::Initial, $product->stock, $product->stock);
            }
        });
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function tracksStock(): bool
    {
        return $this->stock !== null;
    }

    public function isLowOnStock(): bool
    {
        return $this->tracksStock() && $this->stock <= $this->low_stock_at;
    }

    public function scopeLowOnStock(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('stock')->whereColumn('stock', '<=', 'low_stock_at');
    }

    /** A sale or a correction to one. Products the shop doesn't count are left alone. */
    public function moveStock(int $change, StockReason $reason, ?ProductOrder $order = null): void
    {
        $this->changeStock(function (?int $current) use ($change) {
            return $current === null ? null : $current + $change;
        }, $reason, $order);
    }

    /** Goods arriving. On a product not yet counted, this starts counting it. */
    public function restock(int $quantity, ?string $note = null): void
    {
        $this->changeStock(fn (?int $current) => ($current ?? 0) + $quantity, StockReason::Restock, note: $note);
    }

    /** Sets the number actually on the shelf; the history keeps by how much it was off. */
    public function countStock(int $actual, ?string $note = null): void
    {
        $this->changeStock(fn (?int $current) => $actual, StockReason::Count, note: $note);
    }

    /** @param  callable(?int): ?int  $next */
    private function changeStock(callable $next, StockReason $reason, ?ProductOrder $order = null, ?string $note = null): void
    {
        DB::transaction(function () use ($next, $reason, $order, $note) {
            // Read under the lock, so two sales at once can't both start from the same number.
            $current = self::whereKey($this->getKey())->lockForUpdate()->value('stock');
            $balance = $next($current);

            if ($balance === null) {
                return;
            }

            self::whereKey($this->getKey())->update(['stock' => $balance]);

            $this->stock = $balance;
            $this->syncOriginalAttribute('stock');

            $this->writeMovement($reason, $balance - ($current ?? 0), $balance, $order, $note);
        });
    }

    private function writeMovement(StockReason $reason, int $change, int $balance, ?ProductOrder $order = null, ?string $note = null): void
    {
        $this->stockMovements()->create([
            'reason' => $reason,
            'change' => $change,
            'balance' => $balance,
            'product_order_id' => $order?->getKey(),
            'user_id' => auth('web')->id(),
            'note' => $note,
        ]);
    }
}
