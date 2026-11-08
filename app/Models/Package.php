<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A prepaid set of visits sold to an owner, for one or more of their dogs.
 *
 * Each dog has its own count. Visits left are always worked out from appointments
 * rather than stored, so cancelling a booking or undoing a checkout gives the visit
 * back with nothing to keep in sync.
 */
#[Fillable([
    'customer_id', 'service_id', 'service_name', 'included_addons', 'discount_rate', 'price',
    'payment_method', 'paid_at', 'starts_on', 'expires_on', 'cancelled_at', 'refund_amount',
    'refunded_at', 'notes', 'legacy_order_id',
])]
class Package extends Model
{
    use RecordsActivity;

    protected function casts(): array
    {
        return [
            'included_addons' => 'array',
            'payment_method' => PaymentMethod::class,
            'discount_rate' => 'float',
            'paid_at' => 'datetime',
            'starts_on' => 'date',
            'expires_on' => 'date',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function pets(): BelongsToMany
    {
        return $this->belongsToMany(Pet::class)->withPivot(['unit_price', 'total_visits', 'used_adjustment']);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PackageAdjustment::class)->latest('id');
    }

    /**
     * What a package costs: the main service at the 折 rate (9 means 90%), plus the
     * prepaid add-ons at full price, for every visit of every dog.
     *
     * @param  Collection<int, Pet>  $pets
     * @param  Collection<int, Service>  $addons
     */
    public static function quote(Collection $pets, Service $main, Collection $addons, int $visits, float $discountRate): int
    {
        return (int) $pets->sum(fn (Pet $pet) => round($pet->priceFor($main) * $visits * $discountRate / 10)
            + $addons->sum(fn (Service $addon) => $pet->priceFor($addon)) * $visits);
    }

    /**
     * Sells a package on the current settings. Price may be overridden for special deals.
     *
     * @param  array<string, mixed>  $data  pet_ids (or pet_id), service_id, addon_ids, payment_method, price, starts_on, notes
     */
    public static function sell(array $data): self
    {
        $pets = Pet::whereKey($data['pet_ids'] ?? [$data['pet_id'] ?? 0])->get();

        if ($pets->isEmpty() || $pets->pluck('customer_id')->unique()->count() > 1) {
            throw new DomainException('包月方案要選同一位飼主的寵物。');
        }

        $main = Service::findOrFail($data['service_id']);
        $addons = Service::whereKey($data['addon_ids'] ?? [])->orderBy('sort_order')->get();
        $settings = Setting::current();
        $visits = (int) $settings['package_visits'];
        $startsOn = Carbon::parse($data['starts_on'] ?? today());

        return DB::transaction(function () use ($data, $pets, $main, $addons, $settings, $visits, $startsOn) {
            $package = self::create([
                'customer_id' => $pets->first()->customer_id,
                'service_id' => $main->getKey(),
                'service_name' => $main->name,
                'included_addons' => $addons->map(fn (Service $addon) => ['id' => $addon->getKey(), 'name' => $addon->name])->values()->all(),
                'discount_rate' => $settings['package_discount'],
                'price' => filled($data['price'] ?? null)
                    ? (int) $data['price']
                    : self::quote($pets, $main, $addons, $visits, $settings['package_discount']),
                'payment_method' => $data['payment_method'] ?? null,
                'paid_at' => now(),
                'starts_on' => $startsOn,
                'expires_on' => $startsOn->copy()->addMonthsNoOverflow((int) $settings['package_valid_months']),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($pets as $pet) {
                $package->pets()->attach($pet->getKey(), [
                    'unit_price' => $pet->priceFor($main),
                    'total_visits' => $visits,
                ]);
            }

            return $package;
        });
    }

    /** @return list<int> */
    public function addonIds(): array
    {
        return collect($this->included_addons ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether a service is already paid for by this package. */
    public function covers(int $serviceId): bool
    {
        return $this->service_id === $serviceId || in_array($serviceId, $this->addonIds(), true);
    }

    public function hasPet(Pet|int $pet): bool
    {
        return $this->member($pet) !== null;
    }

    /** One dog's row in the package; with no dog given, the only or first one. */
    private function member(Pet|int|null $pet): ?Pet
    {
        $id = $pet instanceof Pet ? $pet->getKey() : $pet;

        return $id === null ? $this->pets->first() : $this->pets->firstWhere('id', $id);
    }

    public function unitPriceFor(Pet|int $pet): int
    {
        return (int) ($this->member($pet)?->pivot->unit_price ?? 0);
    }

    public function totalVisits(Pet|int|null $pet = null): int
    {
        return (int) ($this->member($pet)?->pivot->total_visits ?? 0);
    }

    public function usedVisits(Pet|int|null $pet = null): int
    {
        $member = $this->member($pet);

        if (! $member) {
            return 0;
        }

        $completed = $this->appointments()
            ->where('pet_id', $member->getKey())
            ->where('status', AppointmentStatus::Completed)
            ->count();

        return max(0, $member->pivot->used_adjustment + $completed);
    }

    /** Visits not yet used, whether or not they are booked. */
    public function remainingVisits(Pet|int|null $pet = null): int
    {
        return max(0, $this->totalVisits($pet) - $this->usedVisits($pet));
    }

    /** Visits free to book, leaving out those held by upcoming appointments. */
    public function availableVisits(Pet|int|null $pet = null, ?int $ignoreAppointmentId = null): int
    {
        $member = $this->member($pet);

        if (! $member) {
            return 0;
        }

        $held = $this->appointments()
            ->where('pet_id', $member->getKey())
            ->whereIn('status', AppointmentStatus::active())
            ->when($ignoreAppointmentId, fn (Builder $query) => $query->whereKeyNot($ignoreAppointmentId))
            ->count();

        return max(0, $this->remainingVisits($member) - $held);
    }

    public function totalRemaining(): int
    {
        return (int) $this->pets->sum(fn (Pet $pet) => $this->remainingVisits($pet));
    }

    public function status(): PackageStatus
    {
        return match (true) {
            $this->cancelled_at !== null => PackageStatus::Cancelled,
            $this->totalRemaining() === 0 => PackageStatus::UsedUp,
            $this->expires_on->lt(today()) => PackageStatus::Expired,
            default => PackageStatus::Active,
        };
    }

    public function contents(): string
    {
        return collect([$this->service_name])->concat(collect($this->included_addons ?? [])->pluck('name'))->implode('＋');
    }

    /** How one dog's visits stand, e.g. "毛毛 3/4". */
    public function visitSummary(): string
    {
        return $this->pets->map(fn (Pet $pet) => "{$pet->name} {$this->remainingVisits($pet)}/{$this->totalVisits($pet)}")->implode('、');
    }

    public function label(Pet|int|null $pet = null): string
    {
        $shared = $this->pets->count() > 1 ? "，{$this->pets->count()} 隻共用" : '';

        return "{$this->contents()}（剩 {$this->remainingVisits($pet)} 次{$shared}，到 {$this->expires_on->format('Y/m/d')}）";
    }

    /** Why this package can't cover this dog's visit on that date, or null when it can. */
    public function bookingProblem(Pet|int $pet, CarbonInterface $visitDate, ?int $ignoreAppointmentId = null): ?string
    {
        return match (true) {
            ! $this->hasPet($pet) => '這個包月方案不包含這隻寵物。',
            $this->cancelled_at !== null => '這個包月方案已終止。',
            $this->expires_on->lt($visitDate->copy()->startOfDay()) => '預約日已超過方案到期日（'.$this->expires_on->format('Y/m/d').'），請先延期。',
            $this->availableVisits($pet, $ignoreAppointmentId) < 1 => '這隻寵物的包月次數都已用完或已被其他預約佔用。',
            default => null,
        };
    }

    /** Sets how many visits a dog has used, keeping a record of who changed it and why. */
    public function adjustUsedVisits(Pet|int $pet, int $used, string $reason, ?User $by = null): void
    {
        $member = $this->member($pet) ?? throw new DomainException('這個包月方案不包含這隻寵物。');
        $total = $this->totalVisits($member);

        if ($used < 0 || $used > $total) {
            throw new DomainException("已用次數要介於 0 到 {$total} 次之間。");
        }

        if (blank($reason)) {
            throw new DomainException('請填寫修正原因。');
        }

        DB::transaction(function () use ($member, $used, $reason, $by) {
            $from = $this->usedVisits($member);

            $this->pets()->updateExistingPivot($member->getKey(), [
                'used_adjustment' => $member->pivot->used_adjustment + ($used - $from),
            ]);

            $this->adjustments()->create([
                'pet_id' => $member->getKey(),
                'user_id' => $by?->getKey(),
                'from_used' => $from,
                'to_used' => $used,
                'reason' => $reason,
            ]);
        });

        $this->unsetRelation('pets');
    }

    /** Lengthens the package from its end date, or from today when it has already lapsed. */
    public function extend(int $months): void
    {
        $from = $this->expires_on->lt(today()) ? today() : $this->expires_on;

        $this->update(['expires_on' => $from->copy()->addMonthsNoOverflow($months)]);
    }

    /** Ends the package early, optionally refunding part of what was paid. */
    public function terminate(int $refundAmount = 0, ?string $note = null): void
    {
        if ($this->appointments()->whereIn('status', AppointmentStatus::active())->exists()) {
            throw new DomainException('這個方案還有未完成的預約，請先取消或完成。');
        }

        if ($refundAmount > $this->price) {
            throw new DomainException('退款金額不可超過方案售價。');
        }

        $this->update([
            'cancelled_at' => now(),
            'refund_amount' => $refundAmount,
            'refunded_at' => $refundAmount > 0 ? now() : null,
            'notes' => trim($this->notes."\n".$note) ?: null,
        ]);
    }

    /**
     * Packages with visits left that need the shop's attention: about to lapse or
     * recently lapsed, or with a dog that has no next visit booked.
     *
     * @return array<int, string> Package id => reason.
     */
    public static function needingAttention(): array
    {
        return once(function () {
            $noticeBy = today()->addDays((int) Setting::value('package_expiry_notice_days'));

            return self::query()
                ->whereNull('cancelled_at')
                ->whereDate('expires_on', '>=', today()->subDays(30))
                ->with(['pets', 'appointments' => fn ($query) => $query
                    ->whereIn('status', AppointmentStatus::active())
                    ->where('starts_at', '>=', now())])
                ->get()
                ->mapWithKeys(function (Package $package) use ($noticeBy) {
                    if ($package->totalRemaining() === 0) {
                        return [];
                    }

                    if ($package->expires_on->lt(today())) {
                        return [$package->getKey() => '已逾期'];
                    }

                    if ($package->expires_on->lte($noticeBy)) {
                        return [$package->getKey() => '快到期'];
                    }

                    $unbooked = $package->pets->contains(fn (Pet $pet) => $package->remainingVisits($pet) > 0
                        && ! $package->appointments->contains('pet_id', $pet->getKey()));

                    return $unbooked ? [$package->getKey() => '還沒約下一次'] : [];
                })
                ->all();
        });
    }

    /** The first dog that still has visits, for booking links. */
    public function petToBook(): ?Pet
    {
        return $this->pets->first(fn (Pet $pet) => $this->remainingVisits($pet) > 0);
    }

    public function reminderMessage(): string
    {
        $names = $this->pets->pluck('name')->implode('、');
        $left = $this->pets->count() > 1 ? "共剩 {$this->totalRemaining()} 次" : "還剩 {$this->totalRemaining()} 次";

        return "{$this->customer->name} 您好，{$names} 的包月方案{$left}，到 {$this->expires_on->format('n/j')} 為止，歡迎回覆方便的時間幫您預約下一次。—".config('app.name');
    }
}
