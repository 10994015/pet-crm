<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Livewire\BookingWizard;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\OnlineBooking;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class BookingAbuseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $bath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 08:00'));

        // Plenty of capacity, so a refusal here is never about the slot being taken.
        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'is_groomer' => true, 'is_active' => true]);
        User::factory()->count(30)->create(['is_groomer' => true, 'is_active' => true]);
        $this->bath = Service::create([
            'name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500,
            'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60,
        ]);
    }

    private function book(string $phone = '0912345678'): Appointment
    {
        return OnlineBooking::book([
            'phone' => $phone, 'name' => '客人', 'pet_name' => '豆豆', 'species' => 'dog',
            'size' => 'small', 'service_id' => $this->bath->id, 'starts_at' => '2026-11-11 10:00',
        ]);
    }

    private function pastVisit(Customer $customer, string $at, AppointmentStatus $status): void
    {
        $pet = $customer->pets()->firstOrCreate(['name' => '豆豆']);
        Appointment::create(['pet_id' => $pet->id, 'starts_at' => $at, 'status' => $status]);
    }

    public function test_a_blocked_phone_is_told_to_call_and_nothing_is_booked(): void
    {
        Customer::create(['name' => '惡作劇', 'phone' => '0912345678', 'online_booking_blocked' => true]);

        try {
            $this->book('0912-345-678');
            $this->fail('a blocked phone got through');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('請直接來電', $exception->getMessage());
        }

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_blocked_member_cannot_book_online_either(): void
    {
        $member = Customer::create(['name' => '會員', 'phone' => '0912345678', 'online_booking_blocked' => true]);
        $member->activate('secret123');
        $member->acceptPrivacy();
        $this->actingAs($member, 'member');

        Livewire::test(BookingWizard::class)
            ->set(['serviceId' => $this->bath->id, 'date' => '2026-11-11', 'time' => '10:00', 'step' => 3, 'petName' => '豆豆'])
            ->call('submit')
            ->assertHasErrors('form')
            ->assertSee('無法線上預約');

        $this->assertSame(0, Appointment::count());
    }

    public function test_repeated_no_shows_have_to_call_until_they_come_in_again(): void
    {
        $customer = Customer::create(['name' => '林', 'phone' => '0912345678']);

        $this->pastVisit($customer, '2026-10-01 10:00', AppointmentStatus::NoShow);
        $this->assertFalse($customer->cannotBookOnline(), 'one missed visit is forgiven');

        $this->pastVisit($customer, '2026-10-08 10:00', AppointmentStatus::NoShow);
        $this->assertTrue($customer->cannotBookOnline());

        $this->pastVisit($customer, '2026-10-15 10:00', AppointmentStatus::Completed);
        $this->assertFalse($customer->cannotBookOnline(), 'coming in wipes the slate');
    }

    public function test_old_no_shows_expire_and_the_limit_can_be_turned_off(): void
    {
        $customer = Customer::create(['name' => '林', 'phone' => '0912345678']);
        $this->pastVisit($customer, '2025-01-01 10:00', AppointmentStatus::NoShow);
        $this->pastVisit($customer, '2025-01-08 10:00', AppointmentStatus::NoShow);
        $this->assertFalse($customer->cannotBookOnline(), 'more than a year ago');

        $this->pastVisit($customer, '2026-10-01 10:00', AppointmentStatus::NoShow);
        $this->pastVisit($customer, '2026-10-08 10:00', AppointmentStatus::NoShow);
        $this->assertTrue($customer->cannotBookOnline());

        Setting::store(['booking_max_no_shows' => 0]);
        $this->assertFalse($customer->cannotBookOnline());
    }

    public function test_too_many_unchecked_bookings_stop_new_phones_but_not_regulars(): void
    {
        Setting::store(['booking_max_pending' => 2]);
        $regular = Customer::create(['name' => '老客人', 'phone' => '0900000009']);

        $this->book('0911000001');
        $this->book('0911000002');

        try {
            $this->book('0911000003');
            $this->fail('a third new phone got through');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('待確認的預約比較多', $exception->getMessage());
        }

        $this->assertSame(0, Customer::where('phone', '0911000003')->count(), 'no record left behind');

        $this->assertTrue($this->book($regular->phone)->pet->customer->is($regular));
    }

    public function test_confirming_bookings_makes_room_again_and_zero_means_no_limit(): void
    {
        Setting::store(['booking_max_pending' => 1]);
        $first = $this->book('0911000001');

        $first->update(['confirmed_at' => now()]);
        $this->book('0911000002');
        $this->assertSame(2, Appointment::count());

        Setting::store(['booking_max_pending' => 0]);
        $this->book('0911000003');
        $this->assertSame(3, Appointment::count());
    }

    public function test_guests_must_give_a_mobile_number(): void
    {
        foreach (['02-1234-5678', '0812345678', '091234567', '09123456789'] as $phone) {
            Livewire::test(BookingWizard::class)
                ->set(['serviceId' => $this->bath->id, 'date' => '2026-11-11', 'time' => '10:00', 'step' => 3])
                ->set(['phone' => $phone, 'name' => '客人', 'petName' => '豆豆', 'privacyAccepted' => true])
                ->call('submit')
                ->assertHasErrors('phone');
        }

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_member_on_a_landline_can_still_book(): void
    {
        $member = Customer::create(['name' => '會員', 'phone' => '0212345678']);
        $member->activate('secret123');
        $member->acceptPrivacy();
        $this->actingAs($member, 'member');

        Livewire::test(BookingWizard::class)
            ->set(['serviceId' => $this->bath->id, 'date' => '2026-11-11', 'time' => '10:00', 'step' => 3, 'petName' => '豆豆'])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 4);
    }

    public function test_the_shop_cancels_a_fake_booking_and_blocks_the_phone_in_one_step(): void
    {
        $fake = $this->book();
        $this->actingAs($this->owner);

        Livewire::test(ListAppointments::class, ['activeTab' => 'online'])
            ->callAction(TestAction::make('cancelAndBlock')->table($fake))
            ->assertNotified('已取消預約並封鎖這支電話');

        $this->assertSame(AppointmentStatus::Cancelled, $fake->refresh()->status);
        $this->assertTrue($fake->pet->customer->refresh()->online_booking_blocked);
        $this->assertNotContains(false, array_column(OnlineBooking::slots(Carbon::parse('2026-11-11'), 60), 'available'), 'the slot is free again');

        $this->expectExceptionMessage('請直接來電');
        $this->book();
    }

    public function test_the_action_is_only_offered_on_unconfirmed_online_bookings(): void
    {
        $confirmed = $this->book();
        $confirmed->update(['confirmed_at' => now()]);
        $this->actingAs($this->owner);

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertActionHidden(TestAction::make('cancelAndBlock')->table($confirmed));
    }

    public function test_the_shop_can_block_and_unblock_from_the_customer_page(): void
    {
        $customer = Customer::create(['name' => '林', 'phone' => '0912345678']);
        $this->actingAs($this->owner);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['online_booking_blocked' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($customer->refresh()->online_booking_blocked);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['online_booking_blocked' => false])
            ->call('save');
        $this->assertFalse($customer->refresh()->online_booking_blocked);
    }

    public function test_the_owner_sets_the_limits(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->fillForm(['booking_max_no_shows' => 5, 'booking_max_pending' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5.0, Setting::value('booking_max_no_shows'));
        $this->assertSame(50.0, Setting::value('booking_max_pending'));
    }
}
