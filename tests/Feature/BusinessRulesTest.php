<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetSize;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Filament\Pages\RevenueReport;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Packages\Pages\ManagePackages;
use App\Filament\Resources\ProductOrders\Pages\CreateProductOrder;
use App\Filament\Resources\ProductOrders\Pages\ListProductOrders;
use App\Filament\Widgets\PackagesNeedingAttention;
use App\Models\PackageAdjustment;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Revenue;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Pet $pet;

    private Service $bath;

    private Service $nails;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'username' => 'boss']);
        $this->staff = User::factory()->create(['role' => UserRole::Staff]);
        $this->pet = Customer::create(['name' => '林小美', 'phone' => '0912000001'])
            ->pets()->create(['name' => '毛毛', 'size' => PetSize::Medium]);
        $this->bath = Service::create([
            'name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500,
            'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60,
        ]);
        $this->nails = Service::create([
            'name' => '指甲', 'type' => ServiceType::Optional, 'price_small' => 100,
            'price_medium' => 100, 'price_large' => 150, 'duration_minutes' => 15,
        ]);
    }

    private function sellPackage(array $data = []): Package
    {
        return Package::sell([
            'pet_ids' => [$this->pet->getKey()],
            'service_id' => $this->bath->getKey(),
            'payment_method' => PaymentMethod::Cash,
            ...$data,
        ]);
    }

    private function visit(Package $package, array $attributes = []): Appointment
    {
        $appointment = Appointment::create([
            'pet_id' => $this->pet->getKey(),
            'package_id' => $package->getKey(),
            'starts_at' => now()->addHour(),
            ...$attributes,
        ]);

        $appointment->items()->create([
            'service_id' => $this->bath->getKey(),
            'price' => 700,
            'duration_minutes' => 60,
        ]);

        return $appointment->refresh();
    }

    public function test_package_price_follows_the_settings_and_pet_size(): void
    {
        $package = $this->sellPackage();

        $this->assertSame(700, $package->unitPriceFor($this->pet));
        $this->assertSame(4, $package->totalVisits());
        $this->assertSame($this->pet->customer_id, $package->customer_id);
        $this->assertSame(2520, $package->price); // 700 × 4 × 0.9
        $this->assertEquals(today()->addMonthNoOverflow(), $package->expires_on);
        $this->assertSame(2520, Revenue::total(today()->startOfDay(), today()->endOfDay()));
    }

    public function test_changing_settings_does_not_alter_sold_packages(): void
    {
        $sold = $this->sellPackage();

        Setting::store(['package_discount' => 8.5, 'package_visits' => 5, 'package_valid_months' => 2]);

        $new = $this->sellPackage();

        $this->assertSame(2520, $sold->refresh()->price);
        $this->assertSame(4, $sold->totalVisits());
        $this->assertSame(2975, $new->price); // 700 × 5 × 0.85
        $this->assertSame(5, $new->totalVisits());
        $this->assertEquals(today()->addMonthsNoOverflow(2), $new->expires_on);
    }

    public function test_the_owner_sets_package_terms_on_the_settings_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->fillForm(['package_discount' => 8.5, 'package_visits' => 6])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8.5, Setting::value('package_discount'));
        $this->assertSame(6.0, Setting::value('package_visits'));

        Livewire::test(ShopSettings::class)
            ->fillForm(['package_discount' => 12])
            ->call('save')
            ->assertHasFormErrors(['package_discount']);

        $this->actingAs($this->staff)->get(ShopSettings::getUrl())->assertForbidden();
    }

    public function test_package_visit_is_free_and_counted_only_when_completed(): void
    {
        $package = $this->sellPackage();
        $visit = $this->visit($package);

        $this->assertSame(0, $visit->total);
        $this->assertSame(4, $package->remainingVisits());
        $this->assertSame(3, $package->availableVisits());

        $visit->checkout([]);

        $this->assertSame(3, $package->remainingVisits());
        $this->assertSame(1, $package->usedVisits());
    }

    public function test_cancelling_or_undoing_gives_the_visit_back(): void
    {
        $package = $this->sellPackage();
        $booked = $this->visit($package);
        $this->assertSame(3, $package->availableVisits());

        $booked->update(['status' => AppointmentStatus::Cancelled]);
        $this->assertSame(4, $package->availableVisits());

        $done = $this->visit($package);
        $done->checkout([]);
        $this->assertSame(3, $package->remainingVisits());

        $done->reopen();
        $this->assertSame(4, $package->remainingVisits());
    }

    public function test_editing_a_package_visit_keeps_an_amount_already_recorded(): void
    {
        $visit = $this->visit($this->sellPackage());
        $item = $visit->items()->sole();

        // Like an imported visit where the whole package was paid on the first trip.
        $item->update(['price' => 2880]);
        $item->update(['duration_minutes' => 90]);

        $this->assertSame(2880, $item->refresh()->price);
        $this->assertSame(2880, $visit->refresh()->total);
    }

    public function test_add_ons_are_still_charged_on_a_package_visit(): void
    {
        $visit = $this->visit($this->sellPackage());

        $visit->items()->create(['service_id' => $this->nails->getKey(), 'price' => 100, 'duration_minutes' => 15]);

        $this->assertSame(100, $visit->refresh()->total);
    }

    public function test_a_package_cannot_be_overbooked_or_used_after_expiry(): void
    {
        $package = $this->sellPackage();
        $inside = today()->addDay();

        foreach (range(1, 4) as $i) {
            $this->assertNull($package->bookingProblem($this->pet, $inside));
            $this->visit($package);
        }

        $this->assertNotNull($package->bookingProblem($this->pet, $inside));

        $roomy = $this->sellPackage();
        $this->assertNull($roomy->bookingProblem($this->pet, $roomy->expires_on));
        $this->assertNotNull($roomy->bookingProblem($this->pet, $roomy->expires_on->copy()->addDay()));
    }

    public function test_expired_package_keeps_visits_until_extended(): void
    {
        $package = $this->sellPackage();
        $package->update(['expires_on' => today()->subDay()]);

        $this->assertSame(PackageStatus::Expired, $package->status());
        $this->assertSame(4, $package->remainingVisits());
        $this->assertNotNull($package->bookingProblem($this->pet, today()));

        $package->extend(1);

        $this->assertEquals(today()->addMonthNoOverflow(), $package->expires_on);
        $this->assertSame(PackageStatus::Active, $package->status());
        $this->assertNull($package->bookingProblem($this->pet, today()));
    }

    public function test_extending_an_unexpired_package_adds_to_its_end_date(): void
    {
        $package = $this->sellPackage();
        $end = $package->expires_on->copy();

        $package->extend(2);

        $this->assertEquals($end->addMonthsNoOverflow(2), $package->expires_on);
    }

    public function test_terminating_refunds_on_the_refund_day_and_keeps_earlier_days_intact(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(3));
        $package = $this->sellPackage();
        $saleDay = today()->copy();

        $this->travel(2)->days();
        $package->terminate(1000, '搬家');

        $this->assertSame(PackageStatus::Cancelled, $package->status());
        $this->assertSame(2520, Revenue::total($saleDay->copy()->startOfDay(), $saleDay->copy()->endOfDay()));
        $this->assertSame(-1000, Revenue::total(today()->startOfDay(), today()->endOfDay()));
        $this->assertStringContainsString('搬家', $package->notes);
    }

    public function test_a_package_with_open_bookings_or_too_large_a_refund_cannot_be_terminated(): void
    {
        $package = $this->sellPackage();
        $booked = $this->visit($package);

        try {
            $package->terminate();
            $this->fail('Expected the open booking to block termination.');
        } catch (DomainException) {
            $this->assertNull($package->refresh()->cancelled_at);
        }

        $booked->update(['status' => AppointmentStatus::Cancelled]);

        $this->expectException(DomainException::class);
        $package->terminate($package->price + 1);
    }

    public function test_only_the_owner_can_extend_or_terminate(): void
    {
        $package = $this->sellPackage();

        $this->actingAs($this->staff);
        Livewire::test(ManagePackages::class)
            ->assertActionHidden(TestAction::make('extend')->table($package))
            ->assertActionHidden(TestAction::make('terminate')->table($package));

        $this->actingAs($this->owner);
        Livewire::test(ManagePackages::class)
            ->callAction(TestAction::make('extend')->table($package), ['months' => 3]);

        $this->assertEquals(today()->addMonthNoOverflow()->addMonthsNoOverflow(3), $package->refresh()->expires_on);
    }

    public function test_the_owner_edits_a_package_in_place_instead_of_landing_on_bookings(): void
    {
        $package = $this->sellPackage();

        $this->actingAs($this->staff);
        Livewire::test(ManagePackages::class)
            ->assertActionHidden(TestAction::make('edit')->table($package));

        $this->actingAs($this->owner);
        Livewire::test(ManagePackages::class)
            ->callAction(TestAction::make('edit')->table($package), [
                'price' => 4321,
                'payment_method' => PaymentMethod::Transfer,
                'notes' => '紙本第 92 頁',
            ])
            ->assertHasNoFormErrors();

        $package->refresh();
        $this->assertSame(4321, $package->price);
        $this->assertSame(PaymentMethod::Transfer, $package->payment_method);
        $this->assertSame('紙本第 92 頁', $package->notes);
    }

    public function test_staff_can_sell_a_package_from_the_packages_page(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(ManagePackages::class)
            ->callAction('create', [
                'customer_id' => $this->pet->customer_id,
                'pet_ids' => [$this->pet->getKey()],
                'service_id' => $this->bath->getKey(),
                'payment_method' => PaymentMethod::LinePay->value,
                'starts_on' => today()->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(2520, Package::sole()->price);
    }

    public function test_tiles_pick_services_and_a_new_main_service_replaces_the_old_one(): void
    {
        $other = Service::create(['name' => '全身美容', 'type' => ServiceType::Standard, 'price_medium' => 1400, 'duration_minutes' => 120]);
        $this->actingAs($this->owner);

        $page = Livewire::test(CreateAppointment::class)
            ->set('data.pet_id', $this->pet->getKey())
            ->call('toggleService', $this->bath->getKey())
            ->call('toggleService', $this->nails->getKey())
            ->call('toggleService', $other->getKey());

        $picked = collect($page->get('data.items'))->pluck('service_id')->all();
        $this->assertEqualsCanonicalizing([$this->nails->getKey(), $other->getKey()], $picked);

        $page->call('toggleService', $this->nails->getKey());
        $this->assertSame([$other->getKey()], collect($page->get('data.items'))->pluck('service_id')->values()->all());
    }

    public function test_booking_with_a_package_prices_the_main_service_at_zero(): void
    {
        $package = $this->sellPackage();
        $this->actingAs($this->staff);

        Livewire::test(CreateAppointment::class, )
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => today()->addDay()->setTime(10, 0)->toDateTimeString(),
            ])
            ->set('data.package_id', $package->getKey())
            ->call('toggleService', $this->nails->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame($package->getKey(), $appointment->package_id);
        $this->assertSame(100, $appointment->total);
        $this->assertSame(2, $appointment->items()->count());
    }

    public function test_a_pet_keeps_its_own_price_from_its_last_visit(): void
    {
        $this->assertSame(700, $this->pet->priceFor($this->bath)); // Medium list price.

        $visit = Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => now()->subWeek()]);
        $visit->items()->create(['service_id' => $this->bath->getKey(), 'price' => 850, 'duration_minutes' => 60]);
        $visit->refresh()->checkout(['payment_method' => PaymentMethod::Cash]);

        $this->assertSame(850, $this->pet->priceFor($this->bath));

        // A package visit shows $0, which must not become the dog's price.
        $this->visit($this->sellPackage())->checkout([]);
        $this->assertSame(850, $this->pet->priceFor($this->bath));
        $this->assertSame(3060, $this->sellPackage()->price); // 850 × 4 × 0.9
    }

    public function test_booking_rejects_an_expired_package(): void
    {
        $package = $this->sellPackage();
        $this->actingAs($this->staff);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => $package->expires_on->copy()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            ])
            ->set('data.package_id', $package->getKey())
            ->call('create')
            ->assertHasFormErrors(['package_id']);

        $this->assertSame(0, Appointment::count());
    }

    public function test_last_services_can_be_copied(): void
    {
        $last = Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => now()->subWeek()]);
        $last->items()->create(['service_id' => $this->nails->getKey(), 'price' => 100, 'duration_minutes' => 15]);
        $last->refresh()->checkout(['payment_method' => PaymentMethod::Cash]);
        $this->actingAs($this->staff);

        $page = Livewire::test(CreateAppointment::class)
            ->set('data.pet_id', $this->pet->getKey())
            ->call('copyLastServices');

        $this->assertSame([$this->nails->getKey()], collect($page->get('data.items'))->pluck('service_id')->values()->all());
    }

    public function test_free_visit_checks_out_without_a_payment_method(): void
    {
        $visit = $this->visit($this->sellPackage());
        $this->actingAs($this->staff);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('checkout')->table($visit), ['discount' => 0, 'payment_method' => null])
            ->assertHasNoFormErrors();

        $this->assertSame(AppointmentStatus::Completed, $visit->refresh()->status);
    }

    public function test_owner_can_backdate_a_checkout_for_revenue(): void
    {
        $yesterday = today()->subDay()->setTime(15, 0);
        $visit = Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => today()->setTime(10, 0)]);
        $visit->items()->create(['service_id' => $this->bath->getKey(), 'price' => 700, 'duration_minutes' => 60]);
        $this->actingAs($this->owner);

        Livewire::test(ListAppointments::class)
            ->callAction(TestAction::make('checkout')->table($visit->refresh()), [
                'discount' => 0,
                'payment_method' => PaymentMethod::Cash->value,
                'paid_at' => $yesterday->toDateTimeString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(700, Revenue::total($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay()));
    }

    public function test_product_order_totals_and_refund_count_in_revenue(): void
    {
        $product = Product::create(['name' => '洗毛精', 'price' => 350]);
        $this->actingAs($this->staff);

        Livewire::test(CreateProductOrder::class)
            ->fillForm([
                'payment_method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
                'discount' => 50,
                'items' => [['product_id' => $product->getKey(), 'name' => '洗毛精', 'price' => 350, 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = ProductOrder::sole();
        $this->assertStringStartsWith('PO-', $order->order_number);
        $this->assertSame(650, $order->total);
        $this->assertSame(650, Revenue::total(today()->startOfDay(), today()->endOfDay()));

        Livewire::test(ListProductOrders::class)->assertActionHidden(TestAction::make('refund')->table($order));

        $this->actingAs($this->owner);
        Livewire::test(ListProductOrders::class)->callAction(TestAction::make('refund')->table($order));

        $this->assertSame(0, Revenue::total(today()->startOfDay(), today()->endOfDay()));
        $this->assertFalse($this->owner->can('update', $order->refresh()));
    }

    public function test_report_combines_all_income_for_a_date_range(): void
    {
        $this->sellPackage();
        $visit = Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => now()]);
        $visit->items()->create(['service_id' => $this->nails->getKey(), 'price' => 100, 'duration_minutes' => 15]);
        $visit->refresh()->checkout(['payment_method' => PaymentMethod::Cash]);
        $this->actingAs($this->owner);

        Livewire::test(RevenueReport::class)
            ->assertViewHas('revenue', 2620)
            ->assertViewHas('grooming', 100)
            ->assertViewHas('packageSales', 2520)
            ->assertViewHas('count', 2)
            ->set('from', today()->addDay()->toDateString())
            ->set('to', today()->addDay()->toDateString())
            ->assertViewHas('revenue', 0);
    }

    public function test_excel_exports_download_and_follow_the_current_filters(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('exportExcel')->table())
            ->assertFileDownloaded();

        Livewire::test(RevenueReport::class)->callAction('export')->assertFileDownloaded();

        Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => today()->setTime(9, 0)]);
        Appointment::create(['pet_id' => $this->pet->getKey(), 'starts_at' => today()->addDays(3)]);

        $page = Livewire::test(ListAppointments::class)->instance();
        $this->assertSame(1, $page->getFilteredSortedTableQuery()->count());

        $this->actingAs($this->staff);
        Livewire::test(ListCustomers::class)->assertActionHidden(TestAction::make('exportExcel')->table());
    }

    public function test_customer_and_pets_are_created_on_one_page(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => '王大明',
                'phone' => '0922-111-222',
                'email' => 'wang@example.com',
                'pets' => [
                    ['name' => '小白', 'species' => 'dog', 'size' => 'small', 'gender' => 'male', 'temperament' => 'gentle', 'chip_number' => '900'],
                    ['name' => '小花', 'species' => 'cat', 'size' => 'small', 'gender' => 'female', 'temperament' => 'shy'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::where('phone', '0922111222')->sole();
        $this->assertEqualsCanonicalizing(['小白', '小花'], $customer->pets()->pluck('name')->all());
        $this->assertSame('900', $customer->pets()->where('name', '小白')->value('chip_number'));
    }

    public function test_staff_log_in_with_a_username_or_email(): void
    {
        $user = User::factory()->create(['username' => 'amy', 'password' => 'secret-pass']);

        Livewire::test(Login::class)
            ->fillForm(['login' => 'amy', 'password' => 'wrong-pass'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);
        $this->assertFalse(Auth::check());

        Livewire::test(Login::class)
            ->fillForm(['login' => 'amy', 'password' => 'secret-pass'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertTrue(Auth::user()->is($user));

        Auth::logout();

        Livewire::test(Login::class)
            ->fillForm(['login' => $user->email, 'password' => 'secret-pass'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_notifications_list_packages_that_need_a_call(): void
    {
        $soon = $this->sellPackage();
        $soon->update(['expires_on' => today()->addDays(3)]);
        $unbooked = $this->sellPackage();
        $unbooked->update(['expires_on' => today()->addDays(30)]);
        $booked = $this->sellPackage();
        $booked->update(['expires_on' => today()->addDays(30)]);
        $this->visit($booked, ['starts_at' => now()->addDays(2)]);
        $usedUp = $this->sellPackage();
        $usedUp->update(['expires_on' => today()->addDays(3)]);
        $usedUp->adjustUsedVisits($this->pet, 4, '測試');

        $this->assertSame([
            $soon->getKey() => '快到期',
            $unbooked->getKey() => '還沒約下一次',
        ], Package::needingAttention());

        $this->actingAs($this->owner);
        Livewire::test(PackagesNeedingAttention::class)
            ->assertCanSeeTableRecords([$soon, $unbooked])
            ->assertCanNotSeeTableRecords([$booked, $usedUp]);
    }

    public function test_prepaid_add_ons_are_free_and_added_when_the_package_is_chosen(): void
    {
        $package = $this->sellPackage(['addon_ids' => [$this->nails->getKey()]]);

        $this->assertSame(2520 + 100 * 4, $package->price); // Add-ons at full price for every visit.
        $this->assertSame('洗澡＋指甲', $package->contents());

        $this->actingAs($this->staff);
        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => today()->addDay()->setTime(10, 0)->toDateTimeString(),
            ])
            ->set('data.package_id', $package->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertEqualsCanonicalizing([$this->bath->getKey(), $this->nails->getKey()], $appointment->items->pluck('service_id')->all());
        $this->assertSame(0, $appointment->total);
    }

    public function test_upgrading_the_main_service_charges_only_the_difference(): void
    {
        $fullGroom = Service::create([
            'name' => '大美容', 'type' => ServiceType::Standard, 'price_small' => 1000,
            'price_medium' => 1300, 'price_large' => 1800, 'duration_minutes' => 120,
        ]);
        $package = $this->sellPackage();
        $this->actingAs($this->staff);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => today()->addDay()->setTime(10, 0)->toDateTimeString(),
            ])
            ->call('toggleService', $fullGroom->getKey())
            ->set('data.package_id', $package->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame([$fullGroom->getKey()], $appointment->items->pluck('service_id')->all());
        $this->assertSame(600, $appointment->total); // 1300 − 700 already paid.

        $appointment->checkout([]);
        $this->assertSame(1, $package->usedVisits());
    }

    public function test_a_package_visit_needs_a_main_service(): void
    {
        $package = $this->sellPackage();
        $this->actingAs($this->staff);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'pet_id' => $this->pet->getKey(),
                'starts_at' => today()->addDay()->setTime(10, 0)->toDateTimeString(),
            ])
            ->set('data.package_id', $package->getKey())
            ->set('data.items', [])
            ->call('toggleService', $this->nails->getKey())
            ->call('create')
            ->assertHasFormErrors(['package_id']);
    }

    public function test_owner_corrects_used_visits_with_a_reason_on_record(): void
    {
        $package = $this->sellPackage();
        $this->visit($package)->checkout([]);

        $package->adjustUsedVisits($this->pet, 3, '紙本記到第 3 次', $this->owner);

        $this->assertSame(3, $package->usedVisits());
        $this->assertSame(1, $package->remainingVisits());
        $log = PackageAdjustment::sole();
        $this->assertSame([1, 3, '紙本記到第 3 次', $this->owner->getKey()], [$log->from_used, $log->to_used, $log->reason, $log->user_id]);

        // Corrections stay right as more visits are completed.
        $this->visit($package)->checkout([]);
        $this->assertSame(4, $package->usedVisits());

        foreach ([[-1, '太少'], [5, '太多'], [2, '']] as [$used, $reason]) {
            try {
                $package->adjustUsedVisits($this->pet, $used, $reason);
                $this->fail("Expected {$used} / '{$reason}' to be refused.");
            } catch (DomainException) {
                $this->assertSame(4, $package->usedVisits());
            }
        }

        $this->actingAs($this->staff);
        Livewire::test(ManagePackages::class)->assertActionHidden(TestAction::make('adjust')->table($package));

        $this->actingAs($this->owner);
        Livewire::test(ManagePackages::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('adjust')->table($package), ['pet_id' => $this->pet->getKey(), 'used' => 2, 'reason' => '多算了'])
            ->assertHasNoFormErrors();
        $this->assertSame(2, $package->refresh()->usedVisits());
        $this->assertSame(2, PackageAdjustment::count());
    }

    public function test_dogs_of_one_owner_share_a_package_with_separate_counts(): void
    {
        $sister = $this->pet->customer->pets()->create(['name' => '妹妹', 'size' => PetSize::Small]);
        $package = $this->sellPackage(['pet_ids' => [$this->pet->getKey(), $sister->getKey()]]);

        $this->assertSame(2520 + 1800, $package->price); // 700×4×0.9 + 500×4×0.9
        $this->assertSame(500, $package->unitPriceFor($sister));

        $this->visit($package)->checkout([]);

        $this->assertSame(3, $package->remainingVisits($this->pet));
        $this->assertSame(4, $package->remainingVisits($sister));
        $this->assertSame(7, $package->totalRemaining());
        $this->assertSame('毛毛 3/4、妹妹 4/4', $package->visitSummary());

        $stranger = Customer::create(['name' => '別人', 'phone' => '0933000000'])->pets()->create(['name' => '路人']);
        $this->expectException(DomainException::class);
        $this->sellPackage(['pet_ids' => [$this->pet->getKey(), $stranger->getKey()]]);
    }

    public function test_vip_threshold_comes_from_settings_and_counts_all_spending(): void
    {
        $customer = $this->pet->customer;
        $this->sellPackage();
        $this->assertFalse($customer->isVip());

        Setting::store(['vip_spending' => 2000]);
        $this->assertTrue($customer->isVip());
    }

    public function test_customers_with_packages_or_sales_cannot_be_deleted(): void
    {
        $customer = $this->pet->customer;
        $this->assertTrue($this->owner->can('delete', $customer));

        $this->sellPackage();

        $this->assertFalse($this->owner->can('delete', $customer->fresh()));
        $this->assertFalse($this->owner->can('delete', $this->pet->fresh()));
    }
}
