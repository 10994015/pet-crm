<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Pages\DailySchedule;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\TimeOffs\Pages\ManageTimeOffs;
use App\Livewire\BookingWizard;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\OnlineBooking;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TimeOffTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-11';

    private User $owner;

    private User $staff;

    private Service $bath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 08:00'));

        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'is_groomer' => true, 'is_active' => true]);
        $this->staff = User::factory()->create(['role' => UserRole::Staff, 'is_groomer' => true, 'is_active' => true]);
        $this->bath = Service::create([
            'name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500,
            'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60,
        ]);
    }

    private function book(string $phone, string $at = self::DAY.' 10:00'): Appointment
    {
        return OnlineBooking::book([
            'phone' => $phone, 'name' => '客人', 'pet_name' => '豆豆', 'species' => 'dog',
            'size' => 'small', 'service_id' => $this->bath->id, 'starts_at' => $at,
        ]);
    }

    /** @return list<string> */
    private function bookableDays(): array
    {
        return array_map(fn (Carbon $day) => $day->toDateString(), OnlineBooking::days());
    }

    public function test_a_shop_closure_removes_every_day_it_covers_from_online_booking(): void
    {
        TimeOff::create(['starts_on' => '2026-11-11', 'ends_on' => '2026-11-13']);

        $days = $this->bookableDays();
        $this->assertContains('2026-11-10', $days);
        $this->assertNotContains('2026-11-11', $days);
        $this->assertNotContains('2026-11-12', $days);
        $this->assertNotContains('2026-11-13', $days, 'the last day is closed too');
        $this->assertContains('2026-11-14', $days);

        $this->assertSame([], OnlineBooking::slots(Carbon::parse('2026-11-12'), 60));

        $this->expectExceptionMessage('被約走了');
        $this->book('0912000001', '2026-11-12 10:00');
    }

    public function test_a_closure_that_began_before_today_still_counts(): void
    {
        TimeOff::create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-10']);

        $days = $this->bookableDays();
        $this->assertNotContains('2026-11-10', $days);
        $this->assertContains('2026-11-11', $days);
    }

    public function test_a_groomer_on_leave_takes_one_seat_away_for_those_days_only(): void
    {
        $this->assertSame(2, OnlineBooking::capacity(Carbon::parse(self::DAY)));

        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);

        $this->assertSame(1, OnlineBooking::capacity(Carbon::parse(self::DAY)));
        $this->assertSame(2, OnlineBooking::capacity(Carbon::parse('2026-11-12')));
        $this->assertContains(self::DAY, $this->bookableDays(), 'the shop is still open');

        $this->book('0912000001');

        $this->expectExceptionMessage('被約走了');
        $this->book('0912000002');
    }

    public function test_two_leave_records_for_the_same_person_only_count_once(): void
    {
        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);
        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12']);

        $this->assertSame(1, OnlineBooking::capacity(Carbon::parse(self::DAY)));
    }

    public function test_nothing_can_be_booked_when_every_groomer_is_away(): void
    {
        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);
        TimeOff::create(['user_id' => $this->owner->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);

        $this->assertSame(0, OnlineBooking::capacity(Carbon::parse(self::DAY)));
        $this->assertNotContains(true, array_column(OnlineBooking::slots(Carbon::parse(self::DAY), 60), 'available'));

        Livewire::test(BookingWizard::class)->call('chooseDate', self::DAY)->set('step', 2)->assertSee('約滿了');
    }

    public function test_leave_of_someone_no_longer_grooming_changes_nothing(): void
    {
        $former = User::factory()->create(['is_groomer' => false, 'is_active' => true]);
        TimeOff::create(['user_id' => $former->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);

        $this->assertSame(2, OnlineBooking::capacity(Carbon::parse(self::DAY)));
    }

    public function test_the_owner_adds_a_closure_and_is_warned_about_visits_already_booked(): void
    {
        $this->book('0912000001');
        $this->actingAs($this->owner);

        Livewire::test(ManageTimeOffs::class)
            ->callAction('create', ['user_id' => null, 'starts_on' => self::DAY, 'ends_on' => '2026-11-12', 'reason' => '員工旅遊'])
            ->assertHasNoActionErrors()
            ->assertNotified('這段期間還有 1 筆預約');

        $closure = TimeOff::sole();
        $this->assertNull($closure->user_id);
        $this->assertSame(1, Appointment::whereIn('status', \App\Enums\AppointmentStatus::active())->count(), 'existing visits are not cancelled');
    }

    public function test_the_end_date_cannot_come_before_the_start(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageTimeOffs::class)
            ->callAction('create', ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-11'])
            ->assertHasActionErrors(['ends_on']);

        $this->assertSame(0, TimeOff::count());
    }

    public function test_staff_can_see_the_calendar_but_not_change_it(): void
    {
        $off = TimeOff::create(['starts_on' => self::DAY, 'ends_on' => self::DAY]);
        $this->actingAs($this->staff);

        Livewire::test(ManageTimeOffs::class)
            ->assertCanSeeTableRecords([$off])
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($off))
            ->assertActionHidden(TestAction::make('delete')->table($off));
    }

    public function test_the_shop_cannot_assign_a_visit_to_a_groomer_on_leave(): void
    {
        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);
        $pet = Customer::create(['name' => '林', 'phone' => '0911000000'])->pets()->create(['name' => '狗']);
        $this->actingAs($this->owner);

        Livewire::test(CreateAppointment::class)
            ->fillForm(['pet_id' => $pet->id, 'groomer_id' => $this->staff->id, 'starts_at' => self::DAY.' 10:00'])
            ->call('toggleService', $this->bath->id)
            ->call('create')
            ->assertHasFormErrors(['starts_at']);

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_schedule_shows_who_is_away_and_when_the_shop_is_closed(): void
    {
        TimeOff::create(['user_id' => $this->staff->id, 'starts_on' => self::DAY, 'ends_on' => self::DAY]);
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['date' => self::DAY])->test(DailySchedule::class)
            ->assertSee('請假')
            ->assertDontSee('全店店休');

        TimeOff::create(['starts_on' => self::DAY, 'ends_on' => self::DAY, 'reason' => '過年']);

        Livewire::withQueryParams(['date' => self::DAY])->test(DailySchedule::class)
            ->assertSee('今天全店店休（過年）');

        Livewire::withQueryParams(['date' => '2026-11-12'])->test(DailySchedule::class)
            ->assertDontSee('請假')
            ->assertDontSee('全店店休');
    }
}
