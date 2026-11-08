<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PetGender;
use App\Enums\PetSize;
use App\Enums\PetSpecies;
use App\Enums\PetTemperament;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'customer_id', 'name', 'species', 'breed', 'size', 'gender', 'is_neutered', 'birthday',
    'weight_kg', 'coat_color', 'chip_number', 'temperament', 'photo', 'allergies', 'health_notes',
    'grooming_preferences', 'rabies_vaccine_expires_on', 'visit_interval_weeks',
    'next_visit_due_on', 'last_contacted_at', 'is_active', 'legacy_id',
])]
class Pet extends Model
{
    /** Don't contact the same owner about a visit again within this many days. */
    public const CONTACT_COOLDOWN_DAYS = 14;

    /** Start listing pets this many days before their visit is due. */
    public const REMINDER_LEAD_DAYS = 7;

    protected $attributes = [
        'species' => 'dog',
        'size' => 'small',
        'gender' => 'unknown',
        'temperament' => 'gentle',
        'visit_interval_weeks' => 6,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'species' => PetSpecies::class,
            'size' => PetSize::class,
            'gender' => PetGender::class,
            'temperament' => PetTemperament::class,
            'is_neutered' => 'boolean',
            'is_active' => 'boolean',
            'birthday' => 'date',
            'weight_kg' => 'decimal:2',
            'rabies_vaccine_expires_on' => 'date',
            'next_visit_due_on' => 'date',
            'last_contacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Pet $pet) {
            if ($pet->wasChanged('visit_interval_weeks')) {
                $pet->refreshNextVisit();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)->withPivot(['unit_price', 'total_visits', 'used_adjustment']);
    }

    /**
     * What this pet pays for a service: the amount charged last time, because the shop
     * prices each dog individually, or the list price for its size on a first visit.
     * Package visits are left out, since they show $0 or the whole package.
     */
    public function priceFor(Service $service): int
    {
        $last = AppointmentItem::query()
            ->where('service_id', $service->getKey())
            ->where('price', '>', 0)
            ->where('price_is_estimate', false)
            ->whereHas('appointment', fn (Builder $query) => $query
                ->where('pet_id', $this->getKey())
                ->whereNull('package_id')
                ->where('status', AppointmentStatus::Completed))
            ->latest('id')
            ->value('price');

        return $last ?? $service->priceFor($this->size);
    }

    protected function label(): Attribute
    {
        return Attribute::make(get: fn () => "{$this->name}（{$this->customer?->name} {$this->customer?->phone}）");
    }

    protected function ageLabel(): Attribute
    {
        return Attribute::make(get: function () {
            if (! $this->birthday) {
                return null;
            }

            $age = $this->birthday->diff(now());

            return $age->y > 0 ? "{$age->y} 歲 {$age->m} 個月" : "{$age->m} 個月";
        });
    }

    /**
     * Things the groomer has to know before touching the pet.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->temperament === PetTemperament::Bites) {
            $warnings[] = '會咬人';
        }

        if (filled($this->allergies)) {
            $warnings[] = "過敏：{$this->allergies}";
        }

        if (filled($this->health_notes)) {
            $warnings[] = "健康：{$this->health_notes}";
        }

        if ($this->rabies_vaccine_expires_on?->isPast()) {
            $warnings[] = '狂犬病疫苗已過期';
        }

        return $warnings;
    }

    /** Recalculates when the next visit is due from the latest completed appointment. */
    public function refreshNextVisit(): void
    {
        $lastVisit = $this->appointments()
            ->where('status', AppointmentStatus::Completed)
            ->max('starts_at');

        $this->updateQuietly([
            'next_visit_due_on' => $lastVisit
                ? Carbon::parse($lastVisit)->addWeeks($this->visit_interval_weeks)->toDateString()
                : null,
        ]);
    }

    /** Pets whose visit is due, with nothing booked and no recent contact from the shop. */
    public function scopeDueForReminder(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('next_visit_due_on')
            ->whereDate('next_visit_due_on', '<=', today()->addDays(self::REMINDER_LEAD_DAYS))
            ->whereDoesntHave('appointments', fn (Builder $query) => $query
                ->whereIn('status', AppointmentStatus::active())
                ->where('starts_at', '>=', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('last_contacted_at')
                ->orWhere('last_contacted_at', '<', now()->subDays(self::CONTACT_COOLDOWN_DAYS)));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhereHas('customer', fn (Builder $query) => $query->search($term)));
    }

    public function reminderMessage(): string
    {
        return "{$this->customer->name} 您好，{$this->name} 距離上次美容已經一段時間了，最近想幫牠安排洗澡美容嗎？歡迎回覆方便的時間，我們幫您預約。—".config('app.name');
    }
}
