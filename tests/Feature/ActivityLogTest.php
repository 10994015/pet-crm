<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\OnlineBooking;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => '老闆', 'role' => UserRole::Owner, 'is_active' => true]);
        ActivityLog::query()->delete();
    }

    private function lastLog(): ActivityLog
    {
        return ActivityLog::latest('id')->firstOrFail();
    }

    public function test_a_staff_change_says_who_what_and_from_what_to_what(): void
    {
        $customer = Customer::create(['name' => '林小姐', 'phone' => '0912345678']);
        $this->actingAs($this->owner);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['name' => '林太太'])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = $this->lastLog();
        $this->assertSame('老闆', $log->actor);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('updated', $log->action);
        $this->assertSame('Customer', $log->subject_type);
        $this->assertSame('林太太', $log->subject_label);
        $this->assertContains('名稱：林小姐 → 林太太', $log->lines());
        $this->assertNotNull($log->ip_address);
    }

    public function test_status_changes_read_as_words(): void
    {
        $pet = Customer::create(['name' => '林', 'phone' => '0912345678'])->pets()->create(['name' => '豆豆']);
        $appointment = Appointment::create(['pet_id' => $pet->id, 'starts_at' => '2026-11-11 10:00']);
        $this->actingAs($this->owner);

        $appointment->update(['status' => AppointmentStatus::NoShow]);

        $log = $this->lastLog();
        $this->assertSame('豆豆 2026/11/11 10:00', $log->subject_label);
        $this->assertContains('狀態：已預約 → 未到', $log->lines());
    }

    public function test_deleting_keeps_what_was_there(): void
    {
        $customer = Customer::create(['name' => '林小姐', 'phone' => '0912345678']);
        $this->actingAs($this->owner);

        $customer->delete();

        $log = $this->lastLog();
        $this->assertSame('deleted', $log->action);
        $this->assertContains('名稱：林小姐', $log->lines());
        $this->assertContains('電話：0912345678', $log->lines());
    }

    public function test_passwords_and_codes_are_never_written_down(): void
    {
        $this->actingAs($this->owner);
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $customer = Customer::create(['name' => '林', 'phone' => '0912345678']);

        $staff->update(['password' => 'brand-new-secret']);
        $staffLog = $this->lastLog();

        $customer->issueActivationCode();
        $codeLog = $this->lastLog();

        $customer->activate('member-secret');

        $everything = json_encode(ActivityLog::all()->map->only(['details', 'subject_label']));
        $this->assertStringNotContainsString('brand-new-secret', $everything);
        $this->assertStringNotContainsString('member-secret', $everything);
        $this->assertStringNotContainsString($staff->refresh()->password, str_replace('\\/', '/', $everything), 'not even the hash');
        $this->assertStringNotContainsString((string) $customer->refresh()->password, str_replace('\\/', '/', $everything));

        $this->assertContains('密碼：已變更', $staffLog->lines());
        $this->assertContains('開通碼：已變更', $codeLog->lines());
    }

    public function test_a_guest_booking_is_logged_without_keeping_their_ip_address(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        User::factory()->create(['is_groomer' => true, 'is_active' => true]);
        $bath = Service::create(['name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 500, 'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60]);

        OnlineBooking::book([
            'phone' => '0912345678', 'name' => '客人', 'pet_name' => '豆豆', 'species' => 'dog',
            'size' => 'small', 'service_id' => $bath->id, 'starts_at' => '2026-11-11 10:00',
        ]);

        $logs = ActivityLog::where('subject_type', 'Appointment')->get();
        $this->assertSame('網站訪客', $logs->first()->actor);
        $this->assertNull($logs->first()->ip_address);
        $this->assertSame(0, ActivityLog::whereNotNull('ip_address')->count());
    }

    public function test_a_member_cancelling_is_named(): void
    {
        $member = Customer::create(['name' => '林小姐', 'phone' => '0912345678']);
        $member->activate('secret123');
        $pet = $member->pets()->create(['name' => '豆豆']);
        $appointment = Appointment::create(['pet_id' => $pet->id, 'starts_at' => '2026-11-11 10:00']);

        $this->actingAs($member, 'member');
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->assertSame('會員 林小姐', $this->lastLog()->actor);
    }

    public function test_settings_changes_are_logged_only_when_something_changed(): void
    {
        $this->actingAs($this->owner);

        Setting::store(['vip_spending' => 5000]);
        $this->assertSame('created', $this->lastLog()->action);

        Setting::store(['vip_spending' => 8000]);
        $this->assertContains('設定值：5000 → 8000', $this->lastLog()->lines());
        $count = ActivityLog::count();

        Setting::store(['vip_spending' => 8000]);
        $this->assertSame($count, ActivityLog::count());
    }

    public function test_logging_failing_never_stops_the_work(): void
    {
        Schema::drop('activity_logs');

        $customer = Customer::create(['name' => '林', 'phone' => '0912345678']);

        $this->assertTrue($customer->exists);
    }

    public function test_only_the_owner_can_read_it_and_nobody_can_change_it(): void
    {
        $this->actingAs($this->owner);
        $this->assertTrue(ActivityLogResource::canAccess());
        Customer::create(['name' => '林小姐', 'phone' => '0912345678']);
        $log = $this->lastLog();

        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->assertSee('林小姐')
            ->assertSee('老闆');

        $this->assertFalse($this->owner->can('create', ActivityLog::class));
        $this->assertFalse($this->owner->can('update', $log));
        $this->assertFalse($this->owner->can('delete', $log));

        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
        $this->assertFalse(ActivityLogResource::canAccess());
        $this->get(ActivityLogResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_table_filters_by_type_and_date(): void
    {
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => '林小姐', 'phone' => '0912345678']);
        $customerLog = $this->lastLog();
        Service::create(['name' => '洗澡', 'type' => ServiceType::Standard, 'price_small' => 1, 'price_medium' => 1, 'price_large' => 1, 'duration_minutes' => 60]);
        $serviceLog = $this->lastLog();

        Livewire::test(ListActivityLogs::class)
            ->filterTable('subject_type', 'Customer')
            ->assertCanSeeTableRecords([$customerLog])
            ->assertCanNotSeeTableRecords([$serviceLog]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('period', ['from' => now()->addDay()->toDateString()])
            ->assertCanNotSeeTableRecords([$customerLog, $serviceLog]);

        unset($customer);
    }

    public function test_the_password_command_leaves_a_line(): void
    {
        User::factory()->create(['username' => 'mei', 'name' => '小美']);

        Artisan::call('babygo:reset-password', ['login' => 'mei']);

        $log = ActivityLog::where('action', 'password_reset')->sole();
        $this->assertSame('系統指令', $log->actor);
        $this->assertSame('小美', $log->subject_label);
    }

    public function test_lines_older_than_a_year_are_cleared_nightly(): void
    {
        $this->actingAs($this->owner);
        Customer::create(['name' => '舊', 'phone' => '0900000001']);
        $old = $this->lastLog();

        $this->travel(ActivityLog::KEEP_DAYS + 1)->days();
        Customer::create(['name' => '新', 'phone' => '0900000002']);
        $new = $this->lastLog();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertNull(ActivityLog::find($old->id));
        $this->assertNotNull(ActivityLog::find($new->id));

        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'model:prune')));
    }
}
