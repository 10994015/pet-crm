<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PetSize;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Livewire\BookingWizard;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class MemberCenterTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Pet $pet;

    private Service $bath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 08:00'));

        User::factory()->create(['role' => UserRole::Owner, 'is_groomer' => true, 'is_active' => true]);
        $this->bath = Service::create([
            'name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500,
            'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60,
        ]);
        $this->customer = Customer::create(['name' => '林小美', 'phone' => '0912345678']);
        $this->pet = $this->customer->pets()->create(['name' => '豆豆', 'size' => PetSize::Medium, 'breed' => '柴犬']);
    }

    private function member(): Customer
    {
        $this->customer->activate('secret123');

        return $this->customer->refresh();
    }

    private function visit(string $startsAt, AppointmentStatus $status = AppointmentStatus::Scheduled, ?Pet $pet = null): Appointment
    {
        $appointment = Appointment::create(['pet_id' => ($pet ?? $this->pet)->id, 'starts_at' => $startsAt, 'status' => $status]);
        $appointment->items()->create(['service_id' => $this->bath->id, 'price' => 650, 'duration_minutes' => 60]);

        return tap($appointment)->save();
    }

    public function test_the_member_area_asks_guests_to_sign_in(): void
    {
        $this->get('/member')->assertRedirect('/member/login');
        $this->get('/member/login')->assertOk()->assertSee('會員登入');
        $this->get('/member/activate')->assertOk()->assertSee('開通碼');
    }

    public function test_a_phone_alone_cannot_open_an_account(): void
    {
        $this->post('/member/activate', [
            'phone' => '0912345678', 'code' => 'AAAAAA', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'privacy' => '1',
        ])->assertSessionHasErrors('code');

        $code = $this->customer->issueActivationCode();

        $this->post('/member/activate', [
            'phone' => '0922000000', 'code' => $code, 'password' => 'secret123', 'password_confirmation' => 'secret123', 'privacy' => '1',
        ])->assertSessionHasErrors('code');

        $this->travel(Customer::ACTIVATION_DAYS + 1)->days();
        $this->post('/member/activate', [
            'phone' => '0912345678', 'code' => $code, 'password' => 'secret123', 'password_confirmation' => 'secret123', 'privacy' => '1',
        ])->assertSessionHasErrors('code');

        $this->assertFalse($this->customer->refresh()->isMember());
        $this->assertGuest('member');
    }

    public function test_the_shop_code_opens_the_account_once(): void
    {
        $code = $this->customer->issueActivationCode();

        $this->post('/member/activate', [
            'phone' => '0912-345-678', 'code' => strtolower($code), 'password' => 'secret123', 'password_confirmation' => 'secret123', 'privacy' => '1',
        ])->assertRedirect('/member');

        $this->assertAuthenticatedAs($this->customer->refresh(), 'member');
        $this->assertTrue($this->customer->isMember());
        $this->assertNotNull($this->customer->member_since);
        $this->assertNotNull($this->customer->privacy_accepted_at);

        auth('member')->logout();
        $this->post('/member/activate', [
            'phone' => '0912345678', 'code' => $code, 'password' => 'other1234', 'password_confirmation' => 'other1234', 'privacy' => '1',
        ])->assertSessionHasErrors('code');

        $this->post('/member/login', ['phone' => '0912 345 678', 'password' => 'wrong999'])->assertSessionHasErrors('phone');
        $this->post('/member/login', ['phone' => '0912 345 678', 'password' => 'secret123'])->assertRedirect('/member');
        $this->assertAuthenticated('member');
    }

    public function test_opening_an_account_needs_agreeing_to_the_privacy_notice(): void
    {
        $code = $this->customer->issueActivationCode();

        $this->post('/member/activate', [
            'phone' => '0912345678', 'code' => $code, 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('privacy');

        $this->assertFalse($this->customer->refresh()->isMember());
        $this->assertNull($this->customer->privacy_accepted_at);
    }

    public function test_a_member_who_already_agreed_is_not_asked_again_when_booking(): void
    {
        $member = $this->member();
        $member->acceptPrivacy();
        $this->actingAs($member, 'member');

        Livewire::test(BookingWizard::class)
            ->call('chooseService', $this->bath->id)
            ->call('next')
            ->call('chooseDate', '2026-11-11')
            ->call('chooseTime', '14:00')
            ->call('next')
            ->assertDontSee('隱私權聲明')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 4);
    }

    public function test_signing_in_to_the_website_is_not_signing_in_to_the_shop(): void
    {
        $this->actingAs($this->member(), 'member');

        $this->get('/admin')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_the_overview_shows_only_the_members_own_family(): void
    {
        $member = $this->member();
        $this->visit('2026-11-03 10:00', AppointmentStatus::Completed);
        $this->visit('2026-11-20 14:00');

        $stranger = Customer::create(['name' => '別人', 'phone' => '0933000000']);
        $this->visit('2026-11-21 11:00', pet: $stranger->pets()->create(['name' => '路人狗']));

        $this->actingAs($member, 'member')
            ->get('/member')
            ->assertOk()
            ->assertSee('林小美，您好')
            ->assertSee('豆豆')
            ->assertSee('2026/11/03')
            ->assertSee('11/20')
            ->assertDontSee('路人狗');

        $this->actingAs($member, 'member')->get('/member/history')->assertOk()->assertSee('$650');
    }

    public function test_package_visits_left_are_shown(): void
    {
        $member = $this->member();
        $package = \App\Models\Package::sell(['pet_ids' => [$this->pet->id], 'service_id' => $this->bath->id]);
        $this->visit('2026-11-05 10:00', AppointmentStatus::Completed)->update(['package_id' => $package->id]);

        $this->actingAs($member, 'member')->get('/member')
            ->assertSee($package->contents())
            ->assertSee('剩 3 次');
    }

    public function test_members_cancel_their_own_bookings_in_good_time(): void
    {
        $member = $this->member();
        $later = $this->visit('2026-11-20 14:00');
        $tomorrow = $this->visit('2026-11-10 20:00');

        $stranger = Customer::create(['name' => '別人', 'phone' => '0933000000']);
        $theirs = $this->visit('2026-11-21 11:00', pet: $stranger->pets()->create(['name' => '路人狗']));

        $this->actingAs($member, 'member');

        $this->post("/member/appointments/{$theirs->id}/cancel")->assertNotFound();
        $this->assertSame(AppointmentStatus::Scheduled, $theirs->refresh()->status);

        $this->post("/member/appointments/{$tomorrow->id}/cancel")->assertSessionHas('error');
        $this->assertSame(AppointmentStatus::Scheduled, $tomorrow->refresh()->status, 'too close to the visit');

        $this->post("/member/appointments/{$later->id}/cancel")->assertSessionHas('status');
        $this->assertSame(AppointmentStatus::Cancelled, $later->refresh()->status);
        $this->assertStringContainsString('會員中心自行取消', $later->notes);

        Setting::store(['member_cancel_hours' => 0]);
        $this->assertFalse($this->visit('2026-11-25 14:00')->cancellableByOwner(), 'the shop can turn it off');
    }

    public function test_members_book_their_own_pet_without_retyping_anything(): void
    {
        $member = $this->member();
        $this->actingAs($member, 'member');

        Livewire::test(BookingWizard::class)
            ->assertSet('petId', $this->pet->id)
            ->assertSet('size', 'medium')
            ->assertSee('$700')
            ->call('chooseService', $this->bath->id)
            ->call('next')
            ->call('chooseDate', '2026-11-11')
            ->call('chooseTime', '14:00')
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('以會員')
            ->set('phone', '0999999999')
            ->set('privacyAccepted', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $appointment = Appointment::sole();
        $this->assertTrue($appointment->pet->is($this->pet));
        $this->assertSame(1, Customer::count(), 'booked as the member, not the phone sent by the browser');
        $this->assertNull($appointment->notes);
    }

    public function test_members_cannot_pick_someone_elses_pet(): void
    {
        $stranger = Customer::create(['name' => '別人', 'phone' => '0933000000']);
        $theirs = $stranger->pets()->create(['name' => '路人狗']);
        $this->customer->pets()->create(['name' => '咪咪', 'species' => 'cat']);

        $this->actingAs($this->member(), 'member');

        Livewire::withQueryParams(['pet' => $theirs->id])
            ->test(BookingWizard::class)
            ->assertSet('petId', null)
            ->call('choosePet', $theirs->id)
            ->assertSet('petId', null)
            ->assertSet('petName', '');
    }

    public function test_members_update_contact_details_and_password(): void
    {
        $this->actingAs($this->member(), 'member');

        $this->put('/member/profile', ['line_id' => 'mei0912', 'email' => 'mei@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('mei0912', $this->customer->refresh()->line_id);

        $this->put('/member/password', ['current_password' => 'nope', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->assertSessionHasErrors('current_password');
        $this->put('/member/password', ['current_password' => 'secret123', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(auth('member')->validate(['phone' => '0912345678', 'password' => 'newpass99']));
    }

    public function test_the_shop_issues_codes_and_closes_accounts(): void
    {
        $this->actingAs(User::first());

        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->callAction('activationCode')
            ->assertNotified();

        $this->assertNotNull($this->customer->refresh()->activation_code);
        $this->assertTrue($this->customer->activation_expires_at->isFuture());

        $this->customer->activate('secret123');

        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->callAction('closeMembership');

        $this->assertFalse($this->customer->refresh()->isMember());

        // An open session ends once the shop closes the account.
        $this->actingAs($this->customer, 'member')->get('/member')->assertRedirect('/member/login');
        $this->assertGuest('member');
    }
}
