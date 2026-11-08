<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order', 'legacy_id'])]
class ServiceCategory extends Model
{
    use RecordsActivity;

    protected static function booted(): void
    {
        static::creating(function (ServiceCategory $category) {
            $category->sort_order = $category->sort_order ?: (int) self::max('sort_order') + 1;
        });
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
