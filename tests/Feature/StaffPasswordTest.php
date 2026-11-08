<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class StaffPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_replaces_the_password_and_signs_out_remembered_devices(): void
    {
        $user = User::factory()->create(['username' => 'owner', 'role' => UserRole::Owner, 'password' => 'old-password', 'remember_token' => 'abc']);

        $this->assertSame(0, Artisan::call('babygo:reset-password', ['login' => 'owner']));

        preg_match('/新密碼：(\S+)/u', Artisan::output(), $found);
        $user->refresh();

        $this->assertNotEmpty($found[1]);
        $this->assertTrue(Hash::check($found[1], $user->password), 'the password shown is the one that works');
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertNull($user->remember_token);
    }

    public function test_the_command_also_finds_an_email_and_refuses_an_unknown_account(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'old-password']);

        $this->artisan('babygo:reset-password', ['login' => 'boss@example.com'])->assertSuccessful();
        $this->assertFalse(Hash::check('old-password', $user->refresh()->password));

        $this->artisan('babygo:reset-password', ['login' => 'nobody'])->assertFailed();
    }

    public function test_the_email_reset_is_offered_when_the_site_can_send_mail(): void
    {
        // phpunit.xml uses the array mailer, which counts as able to send.
        $this->assertTrue(Filament::getPanel('admin')->hasPasswordReset());
        $this->get('/admin/login')->assertOk()->assertSee('password-reset');
    }

    public function test_the_customer_page_shows_whether_consent_is_on_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $customer = Customer::create(['name' => '林', 'phone' => '0912000000']);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])->assertSee('沒有網站同意紀錄');

        $customer->acceptPrivacy();

        Livewire::test(EditCustomer::class, ['record' => $customer->id])->assertSee('已於網站同意');
    }
}
