<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'pet_id', 'groomer_id', 'package_id', 'legacy_order_item_id', 'starts_at', 'status', 'notes', 'grooming_notes', 'weight_kg',
    'before_photos', 'after_photos', 'discount', 'payment_method', 'paid_at', 'source', 'booking_code', 'confirmed_at',
])]
class Appointment extends Model
{
    public const DEFAULT_DURATION_MINUTES = 60;

    public const SOURCE_SHOP = 'shop';

    public const SOURCE_ONLINE = 'online';

    protected $attributes = ['status' => 'scheduled', 'discount' => 0, 'source' => self::SOURCE_SHOP];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'payment_method' => PaymentMethod::class,
            'before_photos' => 'array',
            'after_photos' => 'array',
            'weight_kg' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment) {
            $items = $appointment->exists
                ? $appointment->items()->get(['price', 'duration_minutes'])
                : collect();

            $appointment->subtotal = (int) $items->sum('price');
            $appointment->total = max(0, $appointment->subtotal - (int) $appointment->discount);

            $minutes = (int) $items->sum('duration_minutes') ?: self::DEFAULT_DURATION_MINUTES;
            $appointment->ends_at = $appointment->starts_at->copy()->addMinutes($minutes);
        });
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function groomer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'groomer_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /** Booked on the website and not yet looked at by the shop. */
    public function awaitsConfirmation(): bool
    {
        return $this->source === self::SOURCE_ONLINE && $this->confirmed_at === null && $this->status === AppointmentStatus::Scheduled;
    }

    /**
     * How the visit reads to the owner on the website, with a colour tone for the badge.
     *
     * @return array{0: string, 1: string}
     */
    public function publicStatus(): array
    {
        return match (true) {
            $this->awaitsConfirmation() => ['待店家確認', 'wait'],
            $this->status === AppointmentStatus::Scheduled => ['已確認', 'ok'],
            $this->status->isActive() => ['美容中', 'ok'],
            $this->status === AppointmentStatus::Completed => ['已完成', 'done'],
            default => ['已取消', 'off'],
        };
    }

    /** Whether the owner may still cancel it themselves on the website. */
    public function cancellableByOwner(): bool
    {
        $hours = (int) Setting::value('member_cancel_hours');

        return $hours > 0
            && $this->status === AppointmentStatus::Scheduled
            && $this->starts_at->gt(now()->addHours($hours));
    }

    /** Cancels on the owner's behalf, leaving a note so the shop knows who did it. */
    public function cancelByOwner(): void
    {
        $this->update([
            'status' => AppointmentStatus::Cancelled,
            'notes' => trim($this->notes."\n".'客人於 '.now()->format('n/j H:i').' 在會員中心自行取消'),
        ]);
    }

    public function scopeAwaitingConfirmation($query)
    {
        return $query->where('source', self::SOURCE_ONLINE)
            ->whereNull('confirmed_at')
            ->where('status', AppointmentStatus::Scheduled);
    }

    /** Whether the groomer already has an active appointment overlapping this time range. */
    public static function hasConflict(int $groomerId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId = null): bool
    {
        return self::query()
            ->where('groomer_id', $groomerId)
            ->whereIn('status', AppointmentStatus::active())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Closes the appointment: records payment and grooming notes, and schedules the next reminder.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkout(array $data): void
    {
        DB::transaction(function () use ($data) {
            $this->fill([
                'discount' => (int) ($data['discount'] ?? 0),
                'payment_method' => $data['payment_method'] ?? null,
                'grooming_notes' => $data['grooming_notes'] ?? $this->grooming_notes,
                'after_photos' => $data['after_photos'] ?? $this->after_photos,
                'weight_kg' => $data['weight_kg'] ?? $this->weight_kg,
                'status' => AppointmentStatus::Completed,
                'paid_at' => $data['paid_at'] ?? now(),
            ])->save();

            if (filled($data['weight_kg'] ?? null)) {
                $this->pet->updateQuietly(['weight_kg' => $data['weight_kg']]);
            }

            $this->pet->refreshNextVisit();
        });
    }

    /** Undoes a checkout, cancellation or no-show. */
    public function reopen(): void
    {
        DB::transaction(function () {
            $this->update([
                'status' => AppointmentStatus::Scheduled,
                'payment_method' => null,
                'paid_at' => null,
                'discount' => 0,
            ]);

            $this->pet->refreshNextVisit();
        });
    }

    public function reminderMessage(): string
    {
        $time = $this->starts_at->locale('zh_TW')->isoFormat('M/D（dd）HH:mm');

        return "{$this->pet->customer->name} 您好，提醒您 {$this->pet->name} 的美容預約在 {$time}，如需更改請提前告知，謝謝！—".config('app.name');
    }
}
