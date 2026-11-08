<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PetSize;
use App\Enums\PetSpecies;
use App\Enums\ServiceType;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules behind the public booking page: which services and times are offered,
 * and turning a filled-in form into a customer, a pet and an appointment.
 */
class OnlineBooking
{
    /** Upcoming online bookings one phone number may hold, so a prank can't fill the week. */
    public const MAX_OPEN_PER_PHONE = 3;

    public static function enabled(): bool
    {
        return Setting::value('booking_enabled') >= 1;
    }

    /** @return Collection<int, Service> */
    public static function mainServices(): Collection
    {
        return self::bookable()->where('type', ServiceType::Standard)->get();
    }

    /** @return Collection<int, Service> */
    public static function addons(): Collection
    {
        return self::bookable()->where('type', ServiceType::Optional)->get();
    }

    private static function bookable()
    {
        return Service::query()
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('category')
            ->orderBy('sort_order');
    }

    /** How long the chosen services take, in minutes. */
    public static function duration(Collection $services): int
    {
        return (int) $services->sum('duration_minutes') ?: Appointment::DEFAULT_DURATION_MINUTES;
    }

    /** Days a customer can pick, skipping the weekly day off. @return list<Carbon> */
    public static function days(): array
    {
        $closed = Setting::closedWeekdays();
        $last = today()->addDays((int) Setting::value('booking_days_ahead'));
        $days = [];

        for ($day = today(); $day->lte($last); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $closed, true)) {
                $days[] = $day->copy();
            }
        }

        return $days;
    }

    /**
     * Start times on a day, each marked free or taken for a visit of this length.
     *
     * @return list<array{time: string, available: bool}>
     */
    public static function slots(CarbonInterface $day, int $minutes): array
    {
        if (! collect(self::days())->contains(fn (Carbon $open) => $open->isSameDay($day))) {
            return [];
        }

        $settings = Setting::current();
        $step = max(15, (int) $settings['booking_slot_minutes']);
        $close = Carbon::parse($day)->startOfDay()->addMinutes((int) round($settings['booking_close_hour'] * 60));
        $earliest = now()->addHours((int) $settings['booking_lead_hours']);
        $busy = self::busyTimes($day);
        $capacity = self::capacity();
        $slots = [];

        for (
            $start = Carbon::parse($day)->startOfDay()->addMinutes((int) round($settings['booking_open_hour'] * 60));
            $start->copy()->addMinutes($minutes)->lte($close);
            $start = $start->copy()->addMinutes($step)
        ) {
            $end = $start->copy()->addMinutes($minutes);
            $overlapping = $busy->filter(fn (array $range) => $range[0] < $end && $range[1] > $start)->count();

            $slots[] = [
                'time' => $start->format('H:i'),
                'available' => $start->gte($earliest) && $overlapping < $capacity,
            ];
        }

        return $slots;
    }

    public static function isAvailable(CarbonInterface $start, int $minutes): bool
    {
        return collect(self::slots($start, $minutes))
            ->contains(fn (array $slot) => $slot['time'] === $start->format('H:i') && $slot['available']);
    }

    /** How many dogs can be groomed at the same time: one per groomer on the team. */
    public static function capacity(): int
    {
        return max(1, User::activeGroomers()->count());
    }

    /** @return Collection<int, array{0: Carbon, 1: Carbon}> */
    private static function busyTimes(CarbonInterface $day): Collection
    {
        return Appointment::query()
            ->whereIn('status', AppointmentStatus::active())
            ->whereDate('starts_at', $day)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Appointment $appointment) => [$appointment->starts_at, $appointment->ends_at]);
    }

    /**
     * Books the visit. A phone the shop already knows is matched to that customer, and the
     * pet by name, so a regular's history stays together; nothing about them is shown back.
     *
     * @param  array{phone: string, name: string, pet_name: string, species: string, breed?: ?string,
     *     size: string, service_id: int, addon_ids?: list<int>, starts_at: string, notes?: ?string}  $data
     */
    public static function book(array $data): Appointment
    {
        if (! self::enabled()) {
            throw new DomainException('目前暫停線上預約，請直接來電。');
        }

        $main = self::mainServices()->firstWhere('id', (int) $data['service_id'])
            ?? throw new DomainException('這個服務目前無法線上預約，請重新選擇。');
        $services = collect([$main])->merge(self::addons()->whereIn('id', array_map('intval', $data['addon_ids'] ?? [])));
        $start = Carbon::parse($data['starts_at']);
        $phone = Customer::normalizePhone($data['phone']);

        return DB::transaction(function () use ($data, $services, $start, $phone) {
            if (! self::isAvailable($start, self::duration($services))) {
                throw new DomainException('這個時段剛好被約走了，請換一個時間。');
            }

            $customer = Customer::firstWhere('phone', $phone);

            if ($customer && $customer->appointments()->awaitingConfirmation()->where('starts_at', '>=', now())->count() >= self::MAX_OPEN_PER_PHONE) {
                throw new DomainException('這支電話已經有好幾筆待確認的預約，請等店家聯絡或直接來電。');
            }

            $customer ??= Customer::create(['name' => $data['name'], 'phone' => $phone]);

            $pet = $customer->pets()->whereRaw('lower(name) = ?', [Str::lower(trim($data['pet_name']))])->first()
                ?? $customer->pets()->create([
                    'name' => trim($data['pet_name']),
                    'species' => PetSpecies::from($data['species']),
                    'breed' => $data['breed'] ?? null,
                    'size' => PetSize::from($data['size']),
                ]);

            $notes = collect([
                $customer->name !== $data['name'] ? "線上填寫的姓名：{$data['name']}" : null,
                $pet->size->value !== $data['size'] ? '線上選的體型：'.PetSize::from($data['size'])->getLabel() : null,
                filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            ])->filter()->implode("\n");

            $appointment = Appointment::create([
                'pet_id' => $pet->getKey(),
                'starts_at' => $start,
                'notes' => $notes ?: null,
                'source' => Appointment::SOURCE_ONLINE,
                'booking_code' => self::newCode(),
            ]);

            foreach ($services as $service) {
                $appointment->items()->create([
                    'service_id' => $service->getKey(),
                    'price' => $pet->priceFor($service),
                    'duration_minutes' => $service->duration_minutes,
                ]);
            }

            return $appointment->refresh();
        });
    }

    /** The booking behind a phone and code, or null when they don't belong together. */
    public static function find(string $phone, string $code): ?Appointment
    {
        $phone = Customer::normalizePhone($phone);

        if ($phone === '' || trim($code) === '') {
            return null;
        }

        return Appointment::query()
            ->where('booking_code', Str::upper(trim($code)))
            ->whereHas('pet.customer', fn ($query) => $query->where('phone', $phone))
            ->with(['pet.customer', 'items'])
            ->first();
    }

    private static function newCode(): string
    {
        do {
            $code = collect(range(1, 6))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (Appointment::where('booking_code', $code)->exists());

        return $code;
    }
}
