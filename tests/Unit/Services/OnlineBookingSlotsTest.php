<?php

namespace Tests\Unit\Services;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\OnlineBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The arithmetic behind the time slots offered on the website. */
class OnlineBookingSlotsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-11'; // a Wednesday

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        User::factory()->create(['role' => UserRole::Owner, 'is_groomer' => true, 'is_active' => true]);
    }

    /** @return array<string, bool> */
    private function slots(int $minutes = 60, string $day = self::DAY): array
    {
        return collect(OnlineBooking::slots(Carbon::parse($day), $minutes))->pluck('available', 'time')->all();
    }

    private function busy(string $start, int $minutes, AppointmentStatus $status = AppointmentStatus::Scheduled): void
    {
        $pet = Customer::firstOrCreate(['phone' => '0911000000'], ['name' => '客人'])->pets()->firstOrCreate(['name' => '狗']);
        $appointment = Appointment::create(['pet_id' => $pet->id, 'starts_at' => $start, 'status' => $status]);
        $service = Service::firstOrCreate(['name' => "{$minutes} 分鐘"], ['price_small' => 1, 'price_medium' => 1, 'price_large' => 1, 'duration_minutes' => $minutes]);
        $appointment->items()->create(['service_id' => $service->id, 'price' => 1, 'duration_minutes' => $minutes]);
    }

    public function test_duration_adds_up_the_services_and_never_returns_zero(): void
    {
        $this->assertSame(90, OnlineBooking::duration(collect([new Service(['duration_minutes' => 60]), new Service(['duration_minutes' => 30])])));
        $this->assertSame(Appointment::DEFAULT_DURATION_MINUTES, OnlineBooking::duration(collect()));
        $this->assertSame(Appointment::DEFAULT_DURATION_MINUTES, OnlineBooking::duration(collect([new Service(['duration_minutes' => null])])));
    }

    public function test_the_last_slot_still_finishes_by_closing_time(): void
    {
        $slots = $this->slots(60);
        $this->assertSame('10:00', array_key_first($slots));
        $this->assertSame('18:00', array_key_last($slots));
        $this->assertCount(17, $slots, '10:00 to 18:00 every 30 minutes');

        $this->assertSame('17:00', array_key_last($this->slots(120)));
        $this->assertSame([], $this->slots(10 * 60), 'a visit longer than the day fits nowhere');
    }

    public function test_opening_on_the_half_hour_and_other_steps(): void
    {
        Setting::store(['booking_open_hour' => 9.5, 'booking_slot_minutes' => 60]);
        $this->assertSame(['09:30', '10:30', '11:30'], array_slice(array_keys($this->slots()), 0, 3));

        Setting::store(['booking_slot_minutes' => 5]);
        $this->assertSame(['09:30', '09:45'], array_slice(array_keys($this->slots()), 0, 2), 'steps shorter than 15 minutes are raised to 15');
    }

    public function test_a_visit_ending_exactly_at_a_slot_does_not_block_it(): void
    {
        $this->busy(self::DAY.' 10:00', 60);
        $slots = $this->slots(60);

        $this->assertFalse($slots['10:00']);
        $this->assertFalse($slots['10:30'], 'overlaps 10:30–11:00');
        $this->assertTrue($slots['11:00'], 'starts the moment the other one ends');
        $this->assertFalse($this->slots(90)['09:30'] ?? false, 'there is no 09:30 at all');
    }

    public function test_cancelled_and_no_show_visits_free_their_time(): void
    {
        $this->busy(self::DAY.' 10:00', 60, AppointmentStatus::Cancelled);
        $this->busy(self::DAY.' 10:00', 60, AppointmentStatus::NoShow);

        $this->assertTrue($this->slots()['10:00']);
    }

    public function test_capacity_counts_active_groomers_but_is_never_zero(): void
    {
        $this->assertSame(1, OnlineBooking::capacity());

        User::factory()->create(['is_groomer' => true, 'is_active' => true]);
        User::factory()->create(['is_groomer' => true, 'is_active' => false]);
        User::factory()->create(['is_groomer' => false, 'is_active' => true]);
        $this->assertSame(2, OnlineBooking::capacity(), 'only working groomers count');

        User::query()->update(['is_active' => false]);
        $this->assertSame(1, OnlineBooking::capacity(), 'one dog at a time even if nobody is marked as a groomer');
    }

    public function test_the_lead_time_closes_slots_that_start_too_soon(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 11:10'));
        $slots = $this->slots();

        $this->assertFalse($slots['14:00'], '2 h 50 min away');
        $this->assertTrue($slots['14:30']);

        Setting::store(['booking_lead_hours' => 0]);
        $this->assertFalse($this->slots()['11:00'], 'already started');
        $this->assertTrue($this->slots()['11:30']);
    }

    public function test_days_run_from_today_for_the_set_range_skipping_days_off(): void
    {
        Setting::store(['booking_days_ahead' => 6, 'booking_closed_days' => ['3']]);
        $days = array_map(fn (Carbon $day) => $day->toDateString(), OnlineBooking::days());

        $this->assertSame(['2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'], $days);
        $this->assertSame([], $this->slots(60, self::DAY), 'nothing on the day off');
        $this->assertSame([], $this->slots(60, '2026-11-09'), 'nothing in the past');
        $this->assertSame([], $this->slots(60, '2026-11-17'), 'nothing past the booking window');
    }

    public function test_is_available_only_accepts_real_slot_times(): void
    {
        $this->assertTrue(OnlineBooking::isAvailable(Carbon::parse(self::DAY.' 10:30'), 60));
        $this->assertFalse(OnlineBooking::isAvailable(Carbon::parse(self::DAY.' 10:15'), 60), 'off the 30-minute grid');
        $this->assertFalse(OnlineBooking::isAvailable(Carbon::parse(self::DAY.' 18:30'), 60), 'would end after closing');
    }
}
