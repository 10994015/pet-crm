<?php

namespace Tests\Unit\Models;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    }

    private function make(array $attributes = []): Appointment
    {
        return (new Appointment)->forceFill([
            'starts_at' => Carbon::parse('2026-11-20 10:00'),
            'status' => AppointmentStatus::Scheduled,
            'source' => Appointment::SOURCE_SHOP,
            ...$attributes,
        ]);
    }

    public static function statuses(): array
    {
        return [
            'online, not yet seen' => [['source' => 'online', 'confirmed_at' => null], '待店家確認', 'wait'],
            'online, confirmed' => [['source' => 'online', 'confirmed_at' => '2026-11-10 09:00'], '已確認', 'ok'],
            'booked at the shop' => [[], '已確認', 'ok'],
            'checked in' => [['status' => AppointmentStatus::CheckedIn], '美容中', 'ok'],
            'being groomed' => [['status' => AppointmentStatus::InProgress, 'source' => 'online'], '美容中', 'ok'],
            'done' => [['status' => AppointmentStatus::Completed], '已完成', 'done'],
            'cancelled' => [['status' => AppointmentStatus::Cancelled, 'source' => 'online'], '已取消', 'off'],
            'no-show reads as cancelled' => [['status' => AppointmentStatus::NoShow], '已取消', 'off'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_owners_see_a_plain_status(array $attributes, string $label, string $tone): void
    {
        $this->assertSame([$label, $tone], $this->make($attributes)->publicStatus());
    }

    public function test_only_untouched_online_bookings_await_confirmation(): void
    {
        $this->assertTrue($this->make(['source' => 'online'])->awaitsConfirmation());
        $this->assertFalse($this->make(['source' => 'shop'])->awaitsConfirmation());
        $this->assertFalse($this->make(['source' => 'online', 'confirmed_at' => now()])->awaitsConfirmation());
        $this->assertFalse($this->make(['source' => 'online', 'status' => AppointmentStatus::Cancelled])->awaitsConfirmation(),
            'a cancelled booking needs nothing from the shop');
    }

    public function test_owners_can_cancel_until_the_set_number_of_hours_before(): void
    {
        // Default: 24 hours.
        $this->assertTrue($this->make(['starts_at' => now()->addHours(24)->addMinute()])->cancellableByOwner());
        $this->assertFalse($this->make(['starts_at' => now()->addHours(24)])->cancellableByOwner(), 'exactly 24 hours is too late');
        $this->assertFalse($this->make(['starts_at' => now()->addHours(3)])->cancellableByOwner());

        Setting::store(['member_cancel_hours' => 2]);
        $this->assertTrue($this->make(['starts_at' => now()->addHours(3)])->cancellableByOwner());
    }

    public function test_cancelling_is_off_when_set_to_zero_and_only_for_scheduled_visits(): void
    {
        $later = now()->addWeek();

        $this->assertFalse($this->make(['starts_at' => $later, 'status' => AppointmentStatus::CheckedIn])->cancellableByOwner());
        $this->assertFalse($this->make(['starts_at' => $later, 'status' => AppointmentStatus::Cancelled])->cancellableByOwner());

        Setting::store(['member_cancel_hours' => 0]);
        $this->assertFalse($this->make(['starts_at' => $later])->cancellableByOwner());
    }
}
