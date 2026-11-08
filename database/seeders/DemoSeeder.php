<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetSize;
use App\Enums\PetTemperament;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Made-up customers and appointments for trying the system: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $owner = User::where('role', UserRole::Owner)->firstOrFail();
        $staff = User::firstOrCreate(['email' => 'staff@example.com'], [
            'name' => '示範美容師',
            'username' => 'staff',
            'password' => Str::password(14, symbols: false),
            'role' => UserRole::Staff,
        ]);

        $bath = Service::where('name', '基礎洗澡')->firstOrFail();
        $full = Service::where('name', '洗澡＋全身美容')->firstOrFail();
        $nails = Service::where('name', '指甲修剪')->firstOrFail();

        $pets = [
            ['林小美', '0912000001', '毛毛', '貴賓', PetSize::Small, []],
            ['陳大文', '0912000002', '小黑', '柴犬', PetSize::Medium, ['temperament' => PetTemperament::Bites]],
            ['王怡君', '0912000003', '奶茶', '黃金獵犬', PetSize::Large, ['allergies' => '燕麥類洗劑']],
            ['張志明', '0912000004', '咪咪', '英國短毛貓', PetSize::Small, ['species' => 'cat', 'temperament' => PetTemperament::Shy]],
            ['黃雅婷', '0912000005', '豆豆', '米克斯', PetSize::Medium, []],
        ];

        $created = collect($pets)->map(function (array $row) {
            [$owner, $phone, $name, $breed, $size, $extra] = $row;

            return Customer::firstOrCreate(['phone' => $phone], ['name' => $owner])
                ->pets()
                ->firstOrCreate(['name' => $name], ['breed' => $breed, 'size' => $size, ...$extra]);
        });

        [$maomao, $black, $milktea, $mimi, $beans] = $created->all();

        // Overdue with nothing booked, so it shows up in the visit reminders.
        $this->visit($beans, $staff, today()->subWeeks(8)->setTime(11, 0), [$bath], PaymentMethod::Cash);

        // Past visits, so reports and reminders have data.
        foreach (range(1, 8) as $monthsAgo) {
            $this->visit($maomao, $owner, today()->subMonths($monthsAgo)->setTime(10, 0), [$bath, $nails], PaymentMethod::Cash);
            $this->visit($milktea, $staff, today()->subMonths($monthsAgo)->setTime(14, 0), [$full], PaymentMethod::LinePay);
        }

        $this->visit($mimi, $staff, today()->subWeeks(10)->setTime(11, 0), [$bath], PaymentMethod::Card);

        // A package in use, and one about to lapse so the notifications page has something to show.
        $package = Package::sell(['pet_ids' => [$maomao->getKey()], 'service_id' => $bath->getKey(), 'addon_ids' => [$nails->getKey()], 'payment_method' => PaymentMethod::LinePay]);
        $package->update(['expires_on' => today()->addDays(4)]);

        // Product sales.
        foreach ([['洗毛精', 350], ['肉乾零食', 120]] as [$name, $price]) {
            $product = Product::firstOrCreate(['name' => $name], ['price' => $price]);
            $order = ProductOrder::create([
                'customer_id' => $maomao->customer_id,
                'payment_method' => PaymentMethod::Cash,
                'paid_at' => now()->subDays(2),
            ]);
            $order->items()->create(['product_id' => $product->getKey(), 'price' => $price, 'quantity' => 2]);
        }

        // Today's schedule.
        $this->book($black, $owner, today()->setTime(10, 0), [$bath, $nails], AppointmentStatus::InProgress);
        $this->book($maomao, $staff, today()->setTime(13, 0), [$full], AppointmentStatus::Scheduled);
        $this->book($mimi, null, today()->addDay()->setTime(15, 0), [$bath], AppointmentStatus::Scheduled);
    }

    private function book(Pet $pet, ?User $groomer, Carbon $start, array $services, AppointmentStatus $status): Appointment
    {
        $appointment = Appointment::create([
            'pet_id' => $pet->getKey(),
            'groomer_id' => $groomer?->getKey(),
            'starts_at' => $start,
            'status' => $status,
        ]);

        foreach ($services as $service) {
            $appointment->items()->create([
                'service_id' => $service->getKey(),
                'price' => $service->priceFor($pet->size),
                'duration_minutes' => $service->duration_minutes,
            ]);
        }

        return $appointment->refresh();
    }

    private function visit(Pet $pet, User $groomer, Carbon $start, array $services, PaymentMethod $method): void
    {
        $appointment = $this->book($pet, $groomer, $start, $services, AppointmentStatus::InProgress);

        $appointment->checkout(['payment_method' => $method]);
        $appointment->update(['paid_at' => $start->copy()->addHours(2)]);
    }
}
