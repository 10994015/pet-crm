<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetSize;
use App\Enums\PetTemperament;
use App\Enums\UserRole;
use App\Filament\Pages\DailySchedule;
use App\Filament\Pages\RevenueReport;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\ProductOrders\ProductOrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use App\Filament\Pages\VisitReminders;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Pets\PetResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\TrendCards;
use App\Filament\Widgets\TodayAppointments;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroomingShopTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Pet $pet;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staff = User::factory()->create(['role' => UserRole::Staff]);
        $customer = Customer::create(['name' => '林小美', 'phone' => '0912-000-001']);
        $this->pet = $customer->pets()->create(['name' => '毛毛', 'size' => PetSize::Medium]);
        $this->service = Service::create([
            'name' => '基礎洗澡',
            'price_small' => 500,
            'price_medium' => 700,
            'price_large' => 1000,
            'duration_minutes' => 60,
        ]);
    }

    private function appointment(array $attributes = [], ?int $price = 700): Appointment
    {
        $appointment = Appointment::create([
            'pet_id' => $this->pet->getKey(),
            'starts_at' => today()->setTime(10, 0),
            ...$attributes,
        ]);

        $appointment->items()->create([
            'service_id' => $this->service->getKey(),
            'price' => $price,
            'duration_minutes' => 90,
        ]);

        return $appointment->refresh();
    }

    public function test_every_page_renders_for_the_owner(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->owner);

        $urls = [
            '/admin',
            DailySchedule::getUrl(),
            VisitReminders::getUrl(),
            RevenueReport::getUrl(),
            CustomerResource::getUrl('index'),
            CustomerResource::getUrl('create'),
            CustomerResource::getUrl('edit', ['record' => $this->pet->customer]),
            PetResource::getUrl('index'),
            PetResource::getUrl('create'),
            PetResource::getUrl('edit', ['record' => $this->pet]),
            ServiceResource::getUrl('index'),
            UserResource::getUrl('index'),
            ShopSettings::getUrl(),
            PackageResource::getUrl('index'),
            ProductOrderResource::getUrl('index'),
            ProductOrderResource::getUrl('create'),
            ProductResource::getUrl('index'),
            ServiceCategoryResource::getUrl('index'),
            AppointmentResource::getUrl('index'),
            AppointmentResource::getUrl('create', ['pet_id' => $this->pet->getKey()]),
            AppointmentResource::getUrl('edit', ['record' => $appointment]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_each_page_band_shows_numbers_about_that_page(): void
    {
        $this->appointment();
        $this->pet->update(['temperament' => 'bites']);

        $this->actingAs($this->owner);
        $this->get(AppointmentResource::getUrl('index'))->assertSeeInOrder(['今日預約', '1', '美容中', '今日未完成', '明日預約']);
        $this->get(PetResource::getUrl('index'))->assertSeeInOrder(['寵物', '會咬人', '1', '疫苗已過期', '該回訪']);
        $this->get(PetResource::getUrl('edit', ['record' => $this->pet]))->assertSee(['到店次數', '建議回訪', '會咬人']);
        $this->get(CustomerResource::getUrl('edit', ['record' => $this->pet->customer]))->assertSee(['毛毛', '累計消費']);

        $this->actingAs($this->staff);
        $this->get(CustomerResource::getUrl('edit', ['record' => $this->pet->customer]))->assertDontSee('累計消費');
    }

    public function test_staff_cannot_reach_owner_only_pages_or_see_revenue(): void
    {
        $this->actingAs($this->staff);

        $this->get(RevenueReport::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('本月營收')->assertDontSee('營收趨勢')->assertDontSee('今日營收')->assertSee('每月預約數');
    }

    public function test_deactivated_accounts_cannot_enter_the_panel(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->actingAs($this->staff)->get('/admin')->assertForbidden();
    }

    public function test_item_changes_update_totals_and_end_time(): void
    {
        $appointment = $this->appointment(['discount' => 100]);

        $this->assertSame(700, $appointment->subtotal);
        $this->assertSame(600, $appointment->total);
        $this->assertEquals(today()->setTime(11, 30), $appointment->ends_at);

        $appointment->items()->first()->delete();
        $appointment->refresh();

        $this->assertSame(0, $appointment->subtotal);
        $this->assertSame(0, $appointment->total);
        $this->assertEquals(today()->setTime(11, 0), $appointment->ends_at);
    }

    public function test_creating_an_appointment_prices_by_pet_size(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => today()->addDay()->setTime(10, 0)->toDateTimeString(),
            ])
            ->call('toggleService', $this->service->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame(700, $appointment->total);
        $this->assertSame('基礎洗澡', $appointment->items->first()->name);
        $this->assertEquals(today()->addDay()->setTime(11, 0), $appointment->ends_at);
    }

    public function test_a_groomer_cannot_be_double_booked(): void
    {
        $this->appointment(['groomer_id' => $this->staff->getKey()]);
        $this->actingAs($this->owner);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'groomer_id' => $this->staff->getKey(),
                'starts_at' => today()->setTime(11, 0)->toDateTimeString(),
            ])
            ->call('toggleService', $this->service->getKey())
            ->call('create')
            ->assertHasFormErrors(['starts_at']);

        $this->assertSame(1, Appointment::count());
    }

    public function test_a_cancelled_appointment_frees_the_groomer(): void
    {
        $appointment = $this->appointment(['groomer_id' => $this->staff->getKey()]);
        $start = today()->setTime(10, 30);
        $end = today()->setTime(11, 0);

        $this->assertTrue(Appointment::hasConflict($this->staff->getKey(), $start, $end));

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->assertFalse(Appointment::hasConflict($this->staff->getKey(), $start, $end));
    }

    public function test_checkout_records_payment_and_schedules_the_next_visit(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->staff);

        Livewire::test(ListAppointments::class)
            ->callAction(TestAction::make('checkout')->table($appointment), [
                'discount' => 100,
                'payment_method' => PaymentMethod::LinePay->value,
                'grooming_notes' => '皮膚有點紅',
                'weight_kg' => 5.5,
            ])
            ->assertHasNoFormErrors();

        $appointment->refresh();
        $this->pet->refresh();

        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $this->assertSame(600, $appointment->total);
        $this->assertSame(PaymentMethod::LinePay, $appointment->payment_method);
        $this->assertNotNull($appointment->paid_at);
        $this->assertSame('皮膚有點紅', $appointment->grooming_notes);
        $this->assertEquals(5.5, (float) $this->pet->weight_kg);
        $this->assertEquals(today()->addWeeks(6), $this->pet->next_visit_due_on);
    }

    public function test_discount_cannot_exceed_the_subtotal(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->owner);

        Livewire::test(ListAppointments::class)
            ->callAction(TestAction::make('checkout')->table($appointment), [
                'discount' => 800,
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->assertHasFormErrors(['discount']);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);
    }

    public function test_only_the_owner_can_undo_a_checkout(): void
    {
        $appointment = $this->appointment();
        $appointment->checkout(['payment_method' => PaymentMethod::Cash]);

        $this->actingAs($this->staff);
        Livewire::test(ListAppointments::class)
            ->assertActionHidden(TestAction::make('reopen')->table($appointment));
        $this->assertFalse($this->staff->can('update', $appointment));
        $this->assertFalse($this->owner->can('delete', $appointment));

        $this->actingAs($this->owner);
        Livewire::test(ListAppointments::class)
            ->callAction(TestAction::make('reopen')->table($appointment));

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertNull($appointment->paid_at);
        $this->assertNull($this->pet->refresh()->next_visit_due_on);
    }

    public function test_reminders_list_only_pets_that_need_contacting(): void
    {
        $this->pet->update(['next_visit_due_on' => today()->subDay()]);
        $this->assertSame(1, Pet::dueForReminder()->count());

        $this->pet->update(['last_contacted_at' => now()]);
        $this->assertSame(0, Pet::dueForReminder()->count());

        $this->pet->update(['last_contacted_at' => now()->subDays(Pet::CONTACT_COOLDOWN_DAYS + 1)]);
        $this->assertSame(1, Pet::dueForReminder()->count());

        $booked = $this->appointment(['starts_at' => now()->addDays(3)]);
        $this->assertSame(0, Pet::dueForReminder()->count());
        $booked->delete();

        $this->pet->update(['next_visit_due_on' => today()->addDays(Pet::REMINDER_LEAD_DAYS + 1)]);
        $this->assertSame(0, Pet::dueForReminder()->count());

        $this->pet->update(['next_visit_due_on' => today(), 'is_active' => false]);
        $this->assertSame(0, Pet::dueForReminder()->count());
    }

    public function test_changing_the_visit_interval_moves_the_due_date(): void
    {
        $this->appointment()->checkout(['payment_method' => PaymentMethod::Cash]);

        $this->pet->refresh()->update(['visit_interval_weeks' => 4]);

        $this->assertEquals(today()->addWeeks(4), $this->pet->refresh()->next_visit_due_on);
    }

    public function test_phone_numbers_are_normalized_and_duplicates_rejected(): void
    {
        $this->assertSame('0912000001', $this->pet->customer->phone);
        $this->actingAs($this->owner);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => '另一位', 'phone' => '0912 000 001'])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertSame(1, Customer::count());
    }

    public function test_customers_with_history_cannot_be_deleted(): void
    {
        $customer = $this->pet->customer;
        $this->assertTrue($this->owner->can('delete', $customer));
        $this->assertFalse($this->staff->can('delete', $customer));

        $this->appointment();

        $this->assertFalse($this->owner->can('delete', $customer->fresh()));
        $this->assertFalse($this->owner->can('delete', $this->pet->fresh()));
    }

    public function test_warnings_surface_what_groomers_must_know(): void
    {
        $this->pet->update([
            'temperament' => PetTemperament::Bites,
            'allergies' => '燕麥',
            'rabies_vaccine_expires_on' => today()->subDay(),
        ]);

        $this->assertSame(['會咬人', '過敏：燕麥', '狂犬病疫苗已過期'], $this->pet->warnings());
    }

    public function test_revenue_report_counts_only_paid_appointments_in_the_month(): void
    {
        $this->appointment(['starts_at' => now()->setTime(9, 0)]);
        $this->appointment(['starts_at' => now()->setTime(12, 0)])
            ->checkout(['payment_method' => PaymentMethod::Cash, 'discount' => 200]);
        $last = $this->appointment(['starts_at' => now()->subMonthNoOverflow()]);
        $last->checkout(['payment_method' => PaymentMethod::Card]);
        $last->update(['paid_at' => now()->subMonthNoOverflow()]);

        $this->actingAs($this->owner);

        Livewire::test(RevenueReport::class)
            ->assertViewHas('revenue', 500)
            ->assertViewHas('count', 1)
            ->assertViewHas('discount', 200)
            ->set('from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->set('to', now()->subMonthNoOverflow()->endOfMonth()->toDateString())
            ->assertViewHas('revenue', 700);
    }

    public function test_dashboard_counts_appointments_later_this_month(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(9)->setTime(2, 0));
        $this->appointment(['starts_at' => now()->setTime(15, 0)]);
        $this->appointment(['starts_at' => now()->addDays(5)]);
        $this->appointment(['starts_at' => now()->addDays(6), 'status' => AppointmentStatus::Cancelled]);

        $this->actingAs($this->owner);

        Livewire::test(TrendCards::class)->assertSeeInOrder(['本月預約數', '2', '本月營收']);
    }

    public function test_checkout_works_from_the_dashboard_list(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->staff);

        Livewire::test(TodayAppointments::class)
            ->callAction(TestAction::make('checkout')->table($appointment), [
                'discount' => 0,
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    public function test_invalid_dates_in_the_url_fall_back_to_today(): void
    {
        $this->actingAs($this->owner);

        $this->get(DailySchedule::getUrl(['date' => 'not-a-date']))->assertOk();
        $this->get(RevenueReport::getUrl(['from' => 'not-a-date', 'to' => 'nope']))->assertOk();
        $this->get(AppointmentResource::getUrl('create', ['date' => 'not-a-date']))->assertOk();
    }
}
