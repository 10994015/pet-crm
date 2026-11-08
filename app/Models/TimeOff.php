<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Days away on the calendar: the whole shop when there's no groomer, otherwise that groomer's leave. */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'reason'])]
class TimeOff extends Model
{
    use RecordsActivity;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function groomer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activityLabel(): string
    {
        return ($this->groomer?->name ?? '全店店休').' '.$this->starts_on?->format('Y/m/d').'–'.$this->ends_on?->format('Y/m/d');
    }

    public function scopeOn(Builder $query, CarbonInterface $day): Builder
    {
        return $query->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
    }

    public function scopeForShop(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public static function shopClosedOn(CarbonInterface $day): bool
    {
        return self::forShop()->on($day)->exists();
    }

    public static function groomerAwayOn(int $groomerId, CarbonInterface $day): bool
    {
        return self::where('user_id', $groomerId)->on($day)->exists();
    }

    /** Booked visits inside this period, which someone has to move or call off by hand. */
    public function affectedAppointments(): int
    {
        return Appointment::query()
            ->whereIn('status', AppointmentStatus::active())
            ->whereDate('starts_at', '>=', $this->starts_on)
            ->whereDate('starts_at', '<=', $this->ends_on)
            ->when($this->user_id, fn (Builder $query) => $query->where('groomer_id', $this->user_id))
            ->count();
    }
}
