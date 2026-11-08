<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An owner's correction of how many visits a dog has used, kept for the books. */
#[Fillable(['package_id', 'pet_id', 'user_id', 'from_used', 'to_used', 'reason'])]
class PackageAdjustment extends Model
{
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
