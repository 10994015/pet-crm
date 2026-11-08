<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Hash;

/** A pet owner. With a password set, they can also sign in to the website's member area. */
#[Fillable(['name', 'phone', 'backup_phone', 'email', 'line_id', 'address', 'notes', 'legacy_id', 'online_booking_blocked'])]
class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use RecordsActivity;

    /** How long a code from the shop works. */
    public const ACTIVATION_DAYS = 7;

    protected $hidden = ['password', 'remember_token', 'activation_code'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'member_since' => 'datetime',
            'activation_expires_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'online_booking_blocked' => 'boolean',
        ];
    }

    /** Either the shop said so, or they've stopped showing up: both can still call. */
    public function cannotBookOnline(): bool
    {
        $limit = (int) Setting::value('booking_max_no_shows');

        return $this->online_booking_blocked || ($limit > 0 && $this->noShowsInARow() >= $limit);
    }

    /** No-shows in the past year since they last actually came, so coming in wipes the slate. */
    public function noShowsInARow(): int
    {
        $lastVisit = $this->appointments()->where('status', AppointmentStatus::Completed)->max('starts_at');

        return $this->appointments()
            ->where('status', AppointmentStatus::NoShow)
            ->where('starts_at', '>=', now()->subYear())
            ->when($lastVisit, fn ($query) => $query->where('starts_at', '>', $lastVisit))
            ->count();
    }

    /** Keeps when they last agreed to the privacy notice, as proof of their consent. */
    public function acceptPrivacy(): void
    {
        $this->forceFill(['privacy_accepted_at' => now()])->save();
    }

    public function isMember(): bool
    {
        return $this->password !== null;
    }

    /**
     * A fresh code for the owner to open (or reset) their account with. Only its hash is
     * kept, so the shop reads it out once; asking again replaces the old one.
     */
    public function issueActivationCode(): string
    {
        $code = collect(range(1, 6))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');

        $this->forceFill([
            'activation_code' => Hash::make($code),
            'activation_expires_at' => now()->addDays(self::ACTIVATION_DAYS),
        ])->save();

        return $code;
    }

    public function activationCodeMatches(string $code): bool
    {
        return $this->activation_code !== null
            && $this->activation_expires_at?->isFuture()
            && Hash::check(strtoupper(trim($code)), $this->activation_code);
    }

    /** Sets the password and uses up the code. */
    public function activate(string $password): void
    {
        $this->forceFill([
            'password' => $password,
            'member_since' => $this->member_since ?? now(),
            'activation_code' => null,
            'activation_expires_at' => null,
            'remember_token' => null,
        ])->save();
    }

    /** Signs them out everywhere and turns the account off; their records stay. */
    public function closeMembership(): void
    {
        $this->forceFill([
            'password' => null,
            'remember_token' => null,
            'activation_code' => null,
            'activation_expires_at' => null,
        ])->save();
    }

    public function isVip(): bool
    {
        return $this->lifetimeSpending() >= Setting::value('vip_spending');
    }

    /** Grooming, packages and products paid for, less refunds. */
    public function lifetimeSpending(): int
    {
        return (int) $this->appointments()->whereNotNull('paid_at')->sum('total')
            + (int) $this->packages()->whereNotNull('paid_at')->sum('price')
            - (int) $this->packages()->sum('refund_amount')
            + (int) $this->productOrders()->whereNotNull('paid_at')->whereNull('refunded_at')->sum('total');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function productOrders(): HasMany
    {
        return $this->hasMany(ProductOrder::class);
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(Appointment::class, Pet::class);
    }

    /** Stored digits-only so "0912-345-678" and "0912345678" are the same customer. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizePhone($value) ?: null);
    }

    protected function label(): Attribute
    {
        return Attribute::make(get: fn () => "{$this->name}（".($this->phone ?? '無電話').'）');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $phone = self::normalizePhone($term);

        return $query->where(function (Builder $query) use ($term, $phone) {
            $query->where('name', 'like', "%{$term}%");

            if ($phone !== '') {
                $query->orWhere('phone', 'like', "%{$phone}%");
            }
        });
    }

    public static function normalizePhone(?string $value): string
    {
        return preg_replace('/[^\d+]/', '', (string) $value);
    }
}
