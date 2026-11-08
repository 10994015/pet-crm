<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'service_id', 'name', 'price', 'price_is_estimate', 'duration_minutes'])]
class AppointmentItem extends Model
{
    protected function casts(): array
    {
        return ['price_is_estimate' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (AppointmentItem $item) {
            $item->name = $item->name ?: Service::find($item->service_id)?->name;

            // The package already paid for its main service and add-ons, however the item was added.
            // Only on creation: a saved amount, such as an old visit that took the whole
            // package payment, must survive later edits.
            if ($item->appointment?->package?->covers((int) $item->service_id)) {
                $item->price = 0;
            }
        });

        // Totals and the end time always follow the items, whichever screen changed them.
        static::saved(fn (AppointmentItem $item) => $item->appointment->save());
        static::deleted(fn (AppointmentItem $item) => $item->appointment?->save());
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
