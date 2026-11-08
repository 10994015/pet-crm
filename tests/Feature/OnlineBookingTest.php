<?php

namespace Tests\Feature;

use App\Enums\PetSize;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Pages\VisitReminders;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class OnlineBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $bath;

    private Service $nails;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday morning, before the shop opens.
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));

        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'is_groomer' => true, 'is_active' => true]);
        $this->bath = Service::create([
            'name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500,
            'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60,
        ]);
        $this->nails = Service::create([
            'name' => '指甲', 'type' => ServiceType::Optional, 'price_small' => 100,
            'price_medium' => 100, 'price_large' => 150, 'duration_minutes' => 30,
        ]);
    }

    private function book(array $data = []): Appointment
    {
        return OnlineBooking::book([
            'phone' => '0912-345-678',
            'name' => '王小美',
            'pet_name' => '豆豆',
            'species' => 'dog',
            'size' => 'medium',
            'service_id' => $this->bath->id,
            'addon_ids' => [$this->nails->id],
            'starts_at' => '2026-11-11 10:00',
            ...$data,
        ]);
    }

    /** @return array<string, bool> */
    private function slotsOn(string $day, int $minutes = 60): array
    {
        return collect(OnlineBooking::slots(Carbon::parse($day), $minutes))->pluck('available', 'time')->all();
    }

    public function test_the_website_pages_open(): void
    {
        $this->get('/')->assertOk()->assertSee('讓毛孩更可愛')->assertSee('洗澡');
        $this->get('/booking')->assertOk()->assertSeeLivewire(BookingWizard::class);
        $this->get('/my-booking')->assertOk()->assertSee('查詢我的預約');
    }

    public function test_slots_follow_opening_hours_lead_time_and_days_off(): void
    {
        $tomorrow = $this->slotsOn('2026-11-11');
        $this->assertSame('10:00', array_key_first($tomorrow));
        $this->assertSame('18:00', array_key_last($tomorrow), 'a one-hour visit has to finish by 19:00');
        $this->assertNotContains(false, $tomorrow);

        $this->travelTo(Carbon::parse('2026-11-10 09:00'));
        $today = $this->slotsOn('2026-11-10');
        $this->assertFalse($today['11:30'], 'online booking closes three hours ahead');
        $this->assertTrue($today['12:00']);

        Setting::store(['booking_closed_days' => ['3']]);
        $this->assertSame([], $this->slotsOn('2026-11-11'), 'Wednesday is now the day off');
        $this->assertFalse(collect(OnlineBooking::days())->contains(fn ($day) => $day->isWednesday()));
    }

    public function test_a_slot_fills_up_once_every_groomer_is_busy(): void
    {
        $this->book();

        $slots = $this->slotsOn('2026-11-11');
        $this->assertFalse($slots['10:00']);
        $this->assertFalse($slots['10:30'], 'the 90 minute visit runs until 11:30');
        $this->assertFalse($slots['11:00']);
        $this->assertTrue($slots['11:30']);

        User::factory()->create(['role' => UserRole::Staff, 'is_groomer' => true, 'is_active' => true]);
        $this->assertTrue($this->slotsOn('2026-11-11')['10:00'], 'a second groomer can take the same time');

        $this->expectException(DomainException::class);
        $this->book(['phone' => '0922000000']);
        $this->book(['phone' => '0933000000']);
    }

    public function test_a_new_family_is_created_with_the_visit_waiting_for_the_shop(): void
    {
        $appointment = $this->book();

        $this->assertSame(Appointment::SOURCE_ONLINE, $appointment->source);
        $this->assertTrue($appointment->awaitsConfirmation());
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $appointment->booking_code);
        $this->assertSame('0912345678', $appointment->pet->customer->phone);
        $this->assertSame(PetSize::Medium, $appointment->pet->size);
        $this->assertSame(['洗澡', '指甲'], $appointment->items->pluck('name')->all());
        $this->assertSame(800, $appointment->total);
        $this->assertEquals(Carbon::parse('2026-11-11 11:30'), $appointment->ends_at);
    }

    public function test_a_regular_is_matched_by_phone_without_changing_their_details(): void
    {
        $customer = Customer::create(['name' => '林小美', 'phone' => '0912345678']);
        $pet = $customer->pets()->create(['name' => 'Lucky', 'size' => PetSize::Large]);

        $appointment = $this->book(['name' => '小美', 'pet_name' => ' lucky ', 'size' => 'small']);

        $this->assertSame(1, Customer::count());
        $this->assertTrue($appointment->pet->is($pet));
        $this->assertSame('林小美', $customer->refresh()->name);
        $this->assertSame(1150, $appointment->total, 'priced for the dog the shop knows, not the size typed online');
        $this->assertStringContainsString('線上填寫的姓名：小美', $appointment->notes);
        $this->assertStringContainsString('線上選的體型：小型', $appointment->notes);
    }

    public function test_one_phone_cannot_pile_up_unconfirmed_bookings(): void
    {
        User::factory()->count(3)->create(['is_groomer' => true, 'is_active' => true]);

        foreach (range(1, OnlineBooking::MAX_OPEN_PER_PHONE) as $i) {
            $this->book();
        }

        $this->expectExceptionMessage('待確認的預約');
        $this->book();
    }

    public function test_closed_booking_and_hidden_services_are_refused(): void
    {
        $this->bath->update(['is_bookable_online' => false]);

        try {
            $this->book();
            $this->fail('a hidden service was booked');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('無法線上預約', $exception->getMessage());
        }

        $this->bath->update(['is_bookable_online' => true]);
        Setting::store(['booking_enabled' => 0]);

        $this->expectExceptionMessage('暫停線上預約');
        $this->book();
    }

    public function test_the_wizard_walks_through_the_three_steps(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('next')
            ->assertHasErrors('serviceId')
            ->set('size', 'large')
            ->call('chooseService', $this->bath->id)
            ->call('toggleAddon', $this->nails->id)
            ->assertSee('$1,150')
            ->call('next')
            ->assertSet('step', 2)
            ->call('chooseDate', '2026-11-11')
            ->assertSeeHtml("chooseTime('14:00')")
            ->assertDontSee('約滿了')
            ->call('chooseTime', '14:00')
            ->call('next')
            ->assertSet('step', 3)
            ->set('phone', '12345')
            ->call('submit')
            ->assertHasErrors(['phone', 'name', 'petName'])
            ->set('phone', '0912-345-678')
            ->set('name', '王小美')
            ->set('petName', '豆豆')
            ->call('submit')
            ->assertHasErrors('privacyAccepted')
            ->assertSet('step', 3)
            ->set('privacyAccepted', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->assertSee('預約送出囉');

        $appointment = Appointment::sole();
        $this->assertSame('2026-11-11 14:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame(PetSize::Large, $appointment->pet->size);
        $this->assertEquals(now(), $appointment->pet->customer->privacy_accepted_at, 'the agreement is kept on record');
    }

    public function test_a_booking_someone_else_is_holding_up_is_refused_politely(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $inProgress = Cache::lock('online-booking', 60);
        $this->assertTrue($inProgress->get());

        try {
            $this->book();
            $this->fail('booked while another booking was still being written');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('再按一次', $exception->getMessage());
        }

        $this->assertSame(0, Appointment::count());

        $inProgress->release();
        $this->book();
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_refused_booking_does_not_block_the_next_one(): void
    {
        $this->book();

        try {
            $this->book(['phone' => '0922000000']);
            $this->fail('the slot was already taken');
        } catch (DomainException) {
            // Taken, as expected. The lock has to be free again regardless.
        }

        $this->book(['phone' => '0922000000', 'starts_at' => '2026-11-11 14:00']);
        $this->assertSame(2, Appointment::count());
    }

    public function test_the_wizard_ignores_bots_filling_the_hidden_field(): void
    {
        Livewire::test(BookingWizard::class)
            ->set(['serviceId' => $this->bath->id, 'date' => '2026-11-11', 'time' => '14:00', 'step' => 3])
            ->set(['phone' => '0912345678', 'name' => 'bot', 'petName' => 'bot', 'website' => 'http://spam', 'privacyAccepted' => true])
            ->call('submit');

        $this->assertSame(0, Appointment::count());
    }

    public function test_owners_look_up_a_booking_with_phone_and_code(): void
    {
        $appointment = $this->book();

        $this->post('/my-booking', ['phone' => '0912345678', 'code' => strtolower($appointment->booking_code)])
            ->assertOk()
            ->assertSee('豆豆 的美容預約')
            ->assertSee('待店家確認');

        $this->post('/my-booking', ['phone' => '0999999999', 'code' => $appointment->booking_code])
            ->assertOk()
            ->assertSee('找不到這筆預約')
            ->assertDontSee('豆豆 的美容預約');

        $appointment->update(['notes' => '內部：上次很兇']);
        $this->post('/my-booking', ['phone' => '0912345678', 'code' => $appointment->booking_code])
            ->assertDontSee('上次很兇');
    }

    public function test_the_shop_sees_and_confirms_online_bookings(): void
    {
        $appointment = $this->book();
        $this->actingAs($this->owner);

        $this->assertSame(1, Appointment::awaitingConfirmation()->count());
        $this->assertGreaterThanOrEqual(1, VisitReminders::pendingCount());

        Livewire::test(ListAppointments::class, ['activeTab' => 'online'])
            ->assertCanSeeTableRecords([$appointment])
            ->callAction(TestAction::make('confirm')->table($appointment));

        $this->assertNotNull($appointment->refresh()->confirmed_at);
        $this->assertFalse($appointment->awaitsConfirmation());

        $this->post('/my-booking', ['phone' => '0912345678', 'code' => $appointment->booking_code])
            ->assertSee('已確認');
    }

    public function test_the_owner_sets_opening_hours_on_the_settings_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(\App\Filament\Pages\ShopSettings::class)
            ->fillForm([
                'booking_open_hour' => 9.5,
                'booking_close_hour' => 18,
                'booking_closed_days' => ['1'],
                'shop_phone' => '02-1234-5678',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([1], Setting::closedWeekdays());
        $this->assertSame('02-1234-5678', Setting::text('shop_phone'));
        $this->assertSame('09:30', array_key_first($this->slotsOn('2026-11-11')));
        $this->get('/')->assertSee('02-1234-5678')->assertSee('週一公休');
    }
}
