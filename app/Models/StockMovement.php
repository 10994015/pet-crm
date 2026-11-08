<?php

namespace App\Models;

use App\Enums\StockReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a product's stock history. Never edited: corrections are new lines. */
#[Fillable(['product_id', 'product_order_id', 'user_id', 'reason', 'change', 'balance', 'note'])]
class StockMovement extends Model
{
    protected function casts(): array
    {
        return ['reason' => StockReason::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductOrder::class, 'product_order_id');
    }
}
