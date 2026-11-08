<?php

namespace App\Models;

use App\Enums\PetSize;
use App\Enums\ServiceType;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_category_id', 'type', 'legacy_id', 'name', 'description', 'price_small', 'price_medium', 'price_large', 'duration_minutes', 'is_active', 'sort_order', 'photo', 'is_bookable_online'])]
class Service extends Model
{
    use RecordsActivity;

    protected $attributes = ['is_active' => true, 'type' => 'standard', 'is_bookable_online' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_bookable_online' => 'boolean', 'type' => ServiceType::class];
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            $service->sort_order = $service->sort_order ?: (int) self::max('sort_order') + 1;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /** Which drawing stands in on the website until the shop uploads a photo. */
    public function artName(): string
    {
        // The service's own name says more than its category ("基礎洗澡" sits under "洗澡美容").
        foreach ([$this->name, (string) $this->category?->name] as $words) {
            $art = match (true) {
                str_contains($words, '貓') => 'cat',
                (bool) preg_match('/剪|造型|美容/u', $words) => 'scissors',
                (bool) preg_match('/護|SPA|spa|保養|按摩/iu', $words) => 'spa',
                (bool) preg_match('/洗|澡|浴/u', $words) => 'bath',
                default => null,
            };

            if ($art) {
                return $art;
            }
        }

        return 'bath';
    }

    public function priceFor(?PetSize $size): int
    {
        return match ($size) {
            PetSize::Medium => $this->price_medium,
            PetSize::Large => $this->price_large,
            default => $this->price_small,
        };
    }
}
