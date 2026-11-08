<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PackageStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\ProductOrder;
use App\Models\Service;
use App\Models\User;
use App\Services\BibidogImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs the importer against a small database shaped like the old system's. */
class BibidogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.bibidog', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->createLegacySchema();
        $this->seedLegacyData();
    }

    private function legacy()
    {
        return DB::connection('bibidog');
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('bibidog');

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('username')->nullable();
            $t->string('email');
            $t->string('password');
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });
        $schema->create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->text('remark')->nullable();
            $t->timestamps();
        });
        $schema->create('pet_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('pets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('pet_type_id')->nullable();
            $t->string('pet_breed')->nullable();
            $t->string('size')->nullable();
            $t->string('name')->nullable();
            $t->string('gender')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('color')->nullable();
            $t->float('weight')->nullable();
            $t->string('chip_number')->nullable();
            $t->boolean('is_neutered')->nullable();
            $t->text('medical_history')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        $schema->create('service_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('sort_order')->default(0);
        });
        $schema->create('service_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_category_id');
            $t->string('name');
            $t->integer('price')->nullable();
            $t->string('config')->default('standard');
            $t->unsignedInteger('sort_order')->default(0);
        });
        $schema->create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pet_id');
            $t->string('order_type');
            $t->date('start_date');
            $t->date('end_date');
            $t->integer('total_amount');
            $t->float('discount')->nullable();
            $t->integer('remaining_visits');
            $t->unsignedInteger('service_count')->default(0);
            $t->string('status')->nullable();
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('service_item_id')->nullable();
            $t->timestamps();
        });
        $schema->create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->integer('price');
            $t->date('service_date');
            $t->text('remarks')->nullable();
        });
        $schema->create('order_item_service_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('service_item_id');
        });
        $schema->create('product_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_number');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->date('order_date')->nullable();
            $t->integer('total_price');
            $t->string('payment_status')->default('paid');
            $t->string('payment_method')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $schema->create('product_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('product_name');
            $t->integer('price');
            $t->integer('quantity');
            $t->text('notes')->nullable();
        });
    }

    private function seedLegacyData(): void
    {
        $db = $this->legacy();

        $db->table('users')->insert(['id' => 1, 'name' => '老闆', 'username' => 'boss', 'email' => 'boss@old.test', 'password' => Hash::make('old-password'), 'is_admin' => true]);
        $db->table('customers')->insert([
            ['id' => 1, 'name' => '林小美', 'phone' => '0912-000-001', 'remark' => null, 'created_at' => '2025-01-05 10:00:00'],
            ['id' => 2, 'name' => '林小美二號', 'phone' => '0912000001', 'remark' => '可能重複', 'created_at' => null],
            ['id' => 3, 'name' => '陳無電話', 'phone' => null, 'remark' => null, 'created_at' => null],
        ]);
        $db->table('pet_types')->insert([['id' => 1, 'name' => '狗'], ['id' => 2, 'name' => '貓']]);
        $db->table('pets')->insert([
            ['id' => 1, 'customer_id' => 1, 'pet_type_id' => 1, 'name' => '毛毛', 'size' => 'medium', 'gender' => 'male', 'chip_number' => 'A1'],
            ['id' => 2, 'customer_id' => 3, 'pet_type_id' => 2, 'name' => '咪咪', 'size' => null, 'gender' => null, 'chip_number' => null],
            ['id' => 3, 'customer_id' => null, 'pet_type_id' => 1, 'name' => '流浪', 'size' => 'small', 'gender' => 'female', 'chip_number' => null],
        ]);
        $db->table('service_categories')->insert(['id' => 1, 'name' => '洗澡']);
        $db->table('service_items')->insert([
            ['id' => 1, 'service_category_id' => 1, 'name' => '基礎洗澡', 'price' => 600, 'config' => 'standard'],
            ['id' => 2, 'service_category_id' => 1, 'name' => '指甲', 'price' => 200, 'config' => 'optional'],
        ]);

        $old = now()->subWeeks(3)->toDateString();
        $recent = now()->subWeek()->toDateString();
        $future = now()->addWeek()->toDateString();

        $db->table('orders')->insert([
            ['id' => 1, 'pet_id' => 2, 'order_type' => 'once', 'start_date' => $old, 'end_date' => $old, 'total_amount' => 800, 'remaining_visits' => 1, 'service_count' => 1, 'status' => 'enabled', 'service_item_id' => 1, 'remarks' => '單次備註'],
            ['id' => 2, 'pet_id' => 1, 'order_type' => 'monthly', 'start_date' => $recent, 'end_date' => $future, 'total_amount' => 600, 'remaining_visits' => 4, 'service_count' => 1, 'status' => 'enabled', 'service_item_id' => 1, 'remarks' => null],
            ['id' => 3, 'pet_id' => 2, 'order_type' => 'once', 'start_date' => $old, 'end_date' => $old, 'total_amount' => 500, 'remaining_visits' => 1, 'service_count' => 0, 'status' => 'disabled', 'service_item_id' => 1, 'remarks' => null],
        ]);
        $db->table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'price' => 777, 'service_date' => $old, 'remarks' => '有打結'],
            ['id' => 2, 'order_id' => 2, 'price' => 2160, 'service_date' => $recent, 'remarks' => null],
            ['id' => 3, 'order_id' => 2, 'price' => 0, 'service_date' => $future, 'remarks' => null],
            ['id' => 4, 'order_id' => 3, 'price' => 500, 'service_date' => $old, 'remarks' => null],
        ]);
        $db->table('order_item_service_items')->insert([
            ['order_item_id' => 1, 'service_item_id' => 1],
            ['order_item_id' => 1, 'service_item_id' => 2],
            ['order_item_id' => 2, 'service_item_id' => 1],
            ['order_item_id' => 3, 'service_item_id' => 1],
            ['order_item_id' => 4, 'service_item_id' => 1],
        ]);
        $db->table('product_orders')->insert([
            ['id' => 1, 'order_number' => 'PO-OLD-1', 'customer_id' => 1, 'order_date' => $recent, 'total_price' => 650, 'payment_status' => 'paid', 'payment_method' => null, 'paid_at' => null],
            ['id' => 2, 'order_number' => 'PO-OLD-2', 'customer_id' => null, 'order_date' => $recent, 'total_price' => 500, 'payment_status' => 'paid', 'payment_method' => 'cash', 'paid_at' => null],
        ]);
        $db->table('product_order_items')->insert([
            ['order_id' => 1, 'product_name' => '洗毛精', 'price' => 350, 'quantity' => 2, 'notes' => null],
            ['order_id' => 2, 'product_name' => '零食', 'price' => 100, 'quantity' => 3, 'notes' => null],
        ]);
    }

    private function import(bool $dryRun = false): array
    {
        return (new BibidogImporter($this->legacy()))->run($dryRun);
    }

    public function test_money_matches_the_old_system_exactly(): void
    {
        $money = $this->import()['reconciliation'];

        $this->assertSame(3437, $money['舊系統美容總額']);
        $this->assertSame(500, $money['其中已取消訂單']);
        $this->assertSame(2937, $money['應匯入美容營收']);
        $this->assertSame($money['應匯入美容營收'], $money['實際匯入美容營收']);
        $this->assertSame(1150, $money['應匯入商品營收']);
        $this->assertSame($money['應匯入商品營收'], $money['實際匯入商品營收']);
    }

    public function test_people_and_pets_carry_over_with_problems_reported(): void
    {
        $issues = $this->import()['issues'];

        $this->assertSame('0912000001', Customer::where('legacy_id', 1)->value('phone'));
        $duplicate = Customer::where('legacy_id', 2)->sole();
        $this->assertNull($duplicate->phone);
        $this->assertStringContainsString('0912000001', $duplicate->notes);
        $this->assertSame('2025-01-05', Customer::where('legacy_id', 1)->sole()->created_at->toDateString());

        $this->assertSame('A1', Pet::where('legacy_id', 1)->value('chip_number'));
        $this->assertSame('cat', Pet::where('legacy_id', 2)->sole()->species->value);
        $this->assertSame('未指定飼主', Pet::where('legacy_id', 3)->sole()->customer->name);
        $this->assertCount(3, $issues);
    }

    public function test_old_passwords_keep_working(): void
    {
        $this->import();

        $boss = User::where('username', 'boss')->sole();
        $this->assertTrue($boss->isOwner());
        $this->assertTrue(Hash::check('old-password', $boss->password));
    }

    public function test_visits_become_appointments_that_keep_their_amounts(): void
    {
        $this->import();

        $once = Appointment::where('legacy_order_item_id', 1)->sole();
        $this->assertSame(AppointmentStatus::Completed, $once->status);
        $this->assertSame(777, $once->total);
        $this->assertCount(2, $once->items);
        $this->assertStringContainsString('有打結', $once->notes);
        $this->assertNotNull($once->paid_at);

        $this->assertSame(AppointmentStatus::Cancelled, Appointment::where('legacy_order_item_id', 4)->sole()->status);
    }

    public function test_monthly_orders_become_packages_without_double_counting_revenue(): void
    {
        $this->import();

        $package = Package::where('legacy_order_id', 2)->sole();
        $this->assertSame(4, $package->totalVisits());
        $this->assertSame(1, $package->usedVisits());
        $this->assertSame(3, $package->remainingVisits());
        $this->assertSame(PackageStatus::Active, $package->status());
        $this->assertNull($package->paid_at);

        $paid = Appointment::where('legacy_order_item_id', 2)->sole();
        $this->assertSame(2160, $paid->total);
        $this->assertSame($package->getKey(), $paid->package_id);

        $upcoming = Appointment::where('legacy_order_item_id', 3)->sole();
        $this->assertSame(AppointmentStatus::Scheduled, $upcoming->status);
        $this->assertNull($upcoming->paid_at);
    }

    public function test_visits_split_across_orders_rejoin_one_package_by_page(): void
    {
        $db = $this->legacy();
        $today = now()->subDays(2)->toDateString();
        $end = now()->addMonth()->toDateString();

        $make = function (int $id, string $type, int $remaining, ?string $remark, int $price, int $petId = 2) use ($db, $today, $end) {
            $db->table('orders')->insert(['id' => $id, 'pet_id' => $petId, 'order_type' => $type, 'start_date' => $today, 'end_date' => $end, 'total_amount' => 600, 'remaining_visits' => $remaining, 'status' => 'enabled', 'service_item_id' => 1, 'remarks' => $remark]);
            $db->table('order_items')->insert(['id' => $id, 'order_id' => $id, 'price' => $price, 'service_date' => $today]);
            $db->table('order_item_service_items')->insert(['order_item_id' => $id, 'service_item_id' => 1]);
        };

        $make(10, 'monthly', 4, null, 2160);          // Opened with no remark…
        $make(11, 'monthly', 4, 'P72包月2', 0);       // …continues on page 72.
        $make(12, 'once', 1, 'P72 包月3', 0);         // Entered as one-off, still the same package.
        $make(13, 'monthly', 4, '包月1 p.5', 2160);   // A new package, with the words reversed.
        $make(14, 'monthly', 4, 'P5包月1', 2160, 1);  // Same page, other dog: its own package.
        $make(15, 'monthly', 2, 'P60', 0, 3);         // Mid-package when first entered.

        $this->import();

        $first = Package::where('legacy_order_id', 10)->sole();
        $this->assertSame(3, $first->usedVisits());
        $this->assertSame(1, $first->remainingVisits());
        $this->assertSame(2160, $first->price);

        $this->assertSame(1, Package::where('legacy_order_id', 13)->sole()->usedVisits());

        $midway = Package::where('legacy_order_id', 15)->sole();
        $this->assertSame(2, $midway->pets->first()->pivot->used_adjustment);
        $this->assertSame(1, $midway->remainingVisits());

        $this->assertSame(0, Package::where('legacy_order_id', 11)->count());
        $this->assertSame(Package::find($first->getKey())->getKey(), Appointment::where('legacy_order_item_id', 12)->value('package_id'));
    }

    public function test_dogs_sharing_a_paper_page_become_one_shared_package(): void
    {
        $db = $this->legacy();
        $db->table('pets')->insert(['id' => 4, 'customer_id' => 1, 'pet_type_id' => 1, 'name' => '妹妹', 'size' => 'small', 'gender' => 'female']);
        $day = now()->subDays(3)->toDateString();
        $end = now()->addMonth()->toDateString();

        foreach ([[40, 1, 'P100包月1', 4320], [41, 4, 'P100包月1', 0], [42, 4, 'P100包月2', 0]] as [$id, $petId, $remark, $price]) {
            $db->table('orders')->insert(['id' => $id, 'pet_id' => $petId, 'order_type' => 'monthly', 'start_date' => $day, 'end_date' => $end, 'total_amount' => 600, 'remaining_visits' => 4, 'status' => 'enabled', 'service_item_id' => 1, 'remarks' => $remark]);
            $db->table('order_items')->insert(['id' => $id, 'order_id' => $id, 'price' => $price, 'service_date' => $day]);
            $db->table('order_item_service_items')->insert(['order_item_id' => $id, 'service_item_id' => 1]);
        }

        $report = $this->import();

        $package = Package::where('legacy_order_id', 40)->sole();
        $this->assertSame(4320, $package->price);
        $this->assertSame(['毛毛', '妹妹'], $package->pets->pluck('name')->all());
        $this->assertSame(3, $package->remainingVisits(Pet::where('legacy_id', 1)->value('id')));
        $this->assertSame(2, $package->remainingVisits(Pet::where('legacy_id', 4)->value('id')));
        $this->assertSame(1, $report['counts']['多隻狗共用的包月方案']);
    }

    public function test_price_tiers_merge_into_one_service_and_each_dog_keeps_its_price(): void
    {
        $db = $this->legacy();
        $db->table('service_items')->insert(['id' => 3, 'service_category_id' => 1, 'name' => '基礎洗澡', 'price' => 900, 'config' => 'standard']);
        $day = now()->subDay()->toDateString();
        $db->table('orders')->insert(['id' => 20, 'pet_id' => 2, 'order_type' => 'once', 'start_date' => $day, 'end_date' => $day, 'total_amount' => 900, 'remaining_visits' => 1, 'status' => 'enabled', 'service_item_id' => 3]);
        $db->table('order_items')->insert(['id' => 20, 'order_id' => 20, 'price' => 900, 'service_date' => $day]);
        $db->table('order_item_service_items')->insert(['order_item_id' => 20, 'service_item_id' => 3]);

        $this->import();

        $bath = Service::where('name', '基礎洗澡')->sole();
        $this->assertSame(600, $bath->price_small); // Lowest tier as the list price.
        $this->assertSame(900, Pet::where('legacy_id', 2)->sole()->priceFor($bath));
    }

    public function test_a_total_that_doesnt_match_list_prices_never_becomes_the_dogs_price(): void
    {
        $this->import();

        // Order 1 charged 777 for 600 + 200: the add-on keeps its price, the main job takes the rest.
        $lines = Appointment::where('legacy_order_item_id', 1)->sole()->items()->orderBy('id')->get();
        $this->assertSame([577, 200], $lines->pluck('price')->all());
        $this->assertTrue($lines->first()->price_is_estimate);

        $bath = Service::where('name', '基礎洗澡')->sole();
        $this->assertSame(600, Pet::where('legacy_id', 2)->sole()->priceFor($bath));
    }

    public function test_bite_warnings_and_line_pay_are_picked_up_from_old_remarks(): void
    {
        $db = $this->legacy();
        $db->table('customers')->insert(['id' => 9, 'name' => '涂先生{咬人}$1200', 'phone' => '0900111222']);
        $db->table('pets')->insert(['id' => 9, 'customer_id' => 9, 'pet_type_id' => 1, 'name' => '小咬', 'size' => 'medium', 'gender' => 'male', 'notes' => '  ', 'medical_history' => "\n\n"]);
        $day = now()->subDay()->toDateString();
        $db->table('orders')->insert(['id' => 30, 'pet_id' => 9, 'order_type' => 'once', 'start_date' => $day, 'end_date' => $day, 'total_amount' => 500, 'remaining_visits' => 1, 'status' => 'enabled', 'service_item_id' => 1]);
        $db->table('order_items')->insert(['id' => 30, 'order_id' => 30, 'price' => 488, 'service_date' => $day, 'remarks' => 'L PAY']);
        $db->table('order_item_service_items')->insert(['order_item_id' => 30, 'service_item_id' => 1]);

        $this->import();

        $pet = Pet::where('legacy_id', 9)->sole();
        $this->assertSame('bites', $pet->temperament->value);
        $this->assertNull($pet->grooming_preferences);
        $this->assertSame('line_pay', Appointment::where('legacy_order_item_id', 30)->sole()->payment_method->value);
    }

    public function test_product_orders_keep_their_totals_and_build_a_catalog(): void
    {
        $this->import();

        $withDiscount = ProductOrder::where('legacy_id', 1)->sole();
        $this->assertSame(650, $withDiscount->total);
        $this->assertSame(50, $withDiscount->discount);
        $this->assertNotNull($withDiscount->paid_at);
        $this->assertNotNull($withDiscount->items->first()->product_id);

        $withGap = ProductOrder::where('legacy_id', 2)->sole();
        $this->assertSame(500, $withGap->total);
        $this->assertSame('cash', $withGap->payment_method->value);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->import();
        $before = [Customer::count(), Pet::count(), Service::count(), Appointment::count(), Package::count(), ProductOrder::count(), User::count()];

        $second = $this->import();

        $this->assertSame($before, [Customer::count(), Pet::count(), Service::count(), Appointment::count(), Package::count(), ProductOrder::count(), User::count()]);
        $this->assertSame([], $second['counts']);
        $this->assertSame($second['reconciliation']['應匯入美容營收'], $second['reconciliation']['實際匯入美容營收']);
    }

    public function test_a_dry_run_writes_nothing_and_never_touches_the_old_database(): void
    {
        $oldCounts = [$this->legacy()->table('customers')->count(), $this->legacy()->table('orders')->count()];

        $report = $this->import(dryRun: true);

        $this->assertSame(3, $report['counts']['客戶']);
        $this->assertSame(0, Customer::count());
        $this->assertSame($oldCounts, [$this->legacy()->table('customers')->count(), $this->legacy()->table('orders')->count()]);
    }

    public function test_the_command_refuses_to_mix_with_existing_customers(): void
    {
        Customer::create(['name' => '示範', 'phone' => '0900']);

        $this->artisan('bibidog:import')->assertFailed();
        $this->assertSame(0, Pet::count());

        $this->artisan('bibidog:import', ['--dry-run' => true, '--allow-existing' => true])->assertSuccessful();
    }
}
