<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetGender;
use App\Enums\PetSize;
use App\Enums\PetTemperament;
use App\Enums\ServiceType;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 100 made-up families with a year of visits, upcoming bookings, packages and product
 * sales, added on top of whatever is there: php artisan db:seed --class=FakeDataSeeder
 *
 * Phones run 0955000001–0955000100, so running it twice adds nothing new.
 */
class FakeDataSeeder extends Seeder
{
    private const COUNT = 100;

    private const SURNAMES = ['陳', '林', '黃', '張', '李', '王', '吳', '劉', '蔡', '楊', '許', '鄭', '謝', '郭', '洪', '曾', '邱', '廖', '賴', '周', '徐', '蘇', '葉', '莊', '呂', '江', '何', '蕭', '羅', '高'];

    private const GIVEN = ['怡君', '雅婷', '志明', '俊傑', '淑芬', '家豪', '佳穎', '宗翰', '欣怡', '冠宇', '詩涵', '建宏', '美玲', '承恩', '郁婷', '彥廷', '靜宜', '柏翰', '思妤', '偉誠', '宜蓁', '凱文', '佩珊', '育成', '筱涵', '子軒', '惠如', '明哲', '雅雯', '品妍'];

    private const DOG_NAMES = ['豆豆', '球球', '可可', '布丁', '奶茶', '麻糬', '小白', '黑糖', '饅頭', '肉圓', '旺財', '來福', 'Lucky', 'Coco', 'Momo', 'Lucy', 'Max', '妞妞', '樂樂', '多多', '米克', '阿肥', '湯圓', '芝麻', '花生', '咖啡', 'Bella', 'Toby', '嘟嘟', '泡芙'];

    private const CAT_NAMES = ['咪咪', '橘子', '喵喵', '小虎', '雪球', '黑嚕嚕', 'Kiki', '阿花', '奶油', '胖胖'];

    /** Breed => size. */
    private const DOG_BREEDS = [
        '貴賓' => 'small', '博美' => 'small', '吉娃娃' => 'small', '瑪爾濟斯' => 'small', '西施' => 'small',
        '約克夏' => 'small', '臘腸' => 'small', '比熊' => 'small', '雪納瑞' => 'small',
        '柴犬' => 'medium', '柯基' => 'medium', '米克斯' => 'medium', '法鬥' => 'medium', '米格魯' => 'medium', '邊境牧羊犬' => 'medium',
        '黃金獵犬' => 'large', '拉布拉多' => 'large', '哈士奇' => 'large', '薩摩耶' => 'large', '秋田' => 'large',
    ];

    private const CAT_BREEDS = ['英國短毛貓', '美國短毛貓', '布偶貓', '波斯貓', '米克斯', '曼赤肯'];

    private const GROOMING_NOTES = [
        '皮膚狀況良好', '耳朵有點髒，已清潔', '腳底毛修短', '下次想留長一點', '肚子有輕微紅疹，請主人留意',
        '很乖，全程配合', '吹風時有點緊張', '打結較多，建議縮短間隔', '指甲偏長，已修剪', null, null, null,
    ];

    /** @var Collection<int, User> */
    private Collection $groomers;

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        mt_srand(20261108);

        $this->groomers = User::activeGroomers()->get();

        if ($this->groomers->isEmpty()) {
            $this->groomers = User::all();
        }

        $main = Service::where('type', ServiceType::Standard)->where('is_active', true)->orderBy('sort_order')->get();
        $addons = Service::where('type', ServiceType::Optional)->where('is_active', true)->orderBy('sort_order')->get();
        $products = $this->products();

        DB::transaction(function () use ($main, $addons, $products) {
            $added = 0;

            foreach (range(1, self::COUNT) as $i) {
                $phone = '0955'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);

                if (Customer::where('phone', $phone)->exists()) {
                    continue;
                }

                $customer = $this->customer($phone, $i);
                $pets = collect(range(1, $this->pick([1 => 70, 2 => 25, 3 => 5])))->map(fn () => $this->pet($customer));

                foreach ($pets as $pet) {
                    $this->history($pet, $main, $addons);
                }

                if ($i % 9 === 0) {
                    $this->package($pets, $main, $addons);
                }

                foreach ($pets as $pet) {
                    $this->upcoming($pet, $main, $addons, online: $i % 13 === 0);
                }

                if (mt_rand(1, 100) <= 25) {
                    $this->productOrder($customer, $products);
                }

                if ($i % 10 === 0) {
                    $customer->activate('demo1234');
                }

                $added++;
            }

            $this->command?->info("新增 {$added} 位假客戶。會員示範帳號：0955000010、0955000020…（密碼 demo1234）");
        });
    }

    private function customer(string $phone, int $i): Customer
    {
        return Customer::create([
            'name' => self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)].self::GIVEN[mt_rand(0, count(self::GIVEN) - 1)],
            'phone' => $phone,
            'line_id' => mt_rand(1, 100) <= 50 ? 'line_'.substr(md5((string) $i), 0, 8) : null,
            'email' => mt_rand(1, 100) <= 30 ? "fake{$i}@example.com" : null,
            'address' => mt_rand(1, 100) <= 40 ? $this->address() : null,
            'notes' => mt_rand(1, 100) <= 10 ? collect(['喜歡下午時段', '請先打電話再傳 LINE', '會自己來接送', '常帶兩隻一起來'])->random() : null,
        ]);
    }

    private function address(): string
    {
        $areas = ['台北市大安區復興南路', '台北市信義區松仁路', '新北市板橋區文化路', '新北市新店區中正路', '台北市中山區民生東路', '台北市內湖區成功路'];

        return $areas[mt_rand(0, count($areas) - 1)].mt_rand(1, 3).'段'.mt_rand(1, 300).'號';
    }

    private function pet(Customer $customer): Pet
    {
        $isCat = mt_rand(1, 100) <= 15;

        if ($isCat) {
            $breed = self::CAT_BREEDS[mt_rand(0, count(self::CAT_BREEDS) - 1)];
            $size = PetSize::Small;
            $name = self::CAT_NAMES[mt_rand(0, count(self::CAT_NAMES) - 1)];
        } else {
            $breed = array_rand(self::DOG_BREEDS);
            $size = PetSize::from(self::DOG_BREEDS[$breed]);
            $name = self::DOG_NAMES[mt_rand(0, count(self::DOG_NAMES) - 1)];
        }

        // Two dogs in one family shouldn't share a name.
        if ($customer->pets()->where('name', $name)->exists()) {
            $name .= '二號';
        }

        $weight = match ($size) {
            PetSize::Small => mt_rand(20, 90) / 10,
            PetSize::Medium => mt_rand(100, 200) / 10,
            PetSize::Large => mt_rand(220, 380) / 10,
        };

        return $customer->pets()->create([
            'name' => $name,
            'species' => $isCat ? 'cat' : 'dog',
            'breed' => $breed,
            'size' => $size,
            'gender' => mt_rand(0, 1) ? PetGender::Male : PetGender::Female,
            'is_neutered' => mt_rand(1, 100) <= 60,
            'birthday' => today()->subMonths(mt_rand(6, 150))->startOfMonth(),
            'weight_kg' => $isCat ? mt_rand(30, 60) / 10 : $weight,
            'temperament' => $this->pick([PetTemperament::Gentle->value => 70, PetTemperament::Shy->value => 12, PetTemperament::Nervous->value => 12, PetTemperament::Bites->value => 6]),
            'allergies' => mt_rand(1, 100) <= 8 ? collect(['燕麥類洗劑', '香精', '雞肉'])->random() : null,
            'health_notes' => mt_rand(1, 100) <= 8 ? collect(['心臟病，避免長時間吹風', '膝蓋不好，抱的時候小心', '皮膚敏感'])->random() : null,
            'rabies_vaccine_expires_on' => mt_rand(1, 100) <= 70 ? today()->addDays(mt_rand(-60, 330)) : null,
            'visit_interval_weeks' => $this->pick([4 => 40, 6 => 40, 8 => 20]),
        ]);
    }

    /** A year of visits, at roughly the pet's usual interval, some that never happened. */
    private function history(Pet $pet, Collection $main, Collection $addons): void
    {
        if (mt_rand(1, 100) <= 8) {
            return; // signed up but never came yet
        }

        $start = today()->subDays(mt_rand(20, 365));
        $usual = $this->servicesFor($pet, $main, $addons);

        for ($day = $start; $day->lt(today()->subDays(2)); $day = $day->copy()->addWeeks($pet->visit_interval_weeks)->addDays(mt_rand(-7, 10))) {
            if ($day->isWednesday()) {
                $day = $day->copy()->addDay();
            }

            $when = $day->copy()->setTime(mt_rand(10, 17), mt_rand(0, 1) * 30);
            $roll = mt_rand(1, 100);
            $status = $roll <= 4 ? AppointmentStatus::Cancelled : ($roll <= 7 ? AppointmentStatus::NoShow : AppointmentStatus::Completed);
            $services = mt_rand(1, 100) <= 80 ? $usual : $this->servicesFor($pet, $main, $addons);

            $this->visit($pet, $when, $services, $status);
        }

        $pet->refreshNextVisit();
    }

    /** @return list<Service> */
    private function servicesFor(Pet $pet, Collection $main, Collection $addons): array
    {
        $services = [$main->count() > 1 && mt_rand(1, 100) <= 45 ? $main[1] : $main->first()];

        foreach ($addons as $addon) {
            if (mt_rand(1, 100) <= 22) {
                $services[] = $addon;
            }
        }

        return $services;
    }

    private function visit(Pet $pet, Carbon $when, array $services, AppointmentStatus $status, ?Package $package = null): Appointment
    {
        $appointment = Appointment::create([
            'pet_id' => $pet->getKey(),
            'groomer_id' => $this->groomers->random()->getKey(),
            'package_id' => $package?->getKey(),
            'starts_at' => $when,
            'status' => AppointmentStatus::Scheduled,
        ]);

        foreach ($services as $service) {
            $appointment->items()->create([
                'service_id' => $service->getKey(),
                'price' => $service->priceFor($pet->size),
                'duration_minutes' => $service->duration_minutes,
            ]);
        }

        if ($status === AppointmentStatus::Completed) {
            $appointment->refresh()->checkout([
                'payment_method' => $this->pick(['cash' => 55, 'line_pay' => 25, 'card' => 12, 'transfer' => 8]),
                'discount' => mt_rand(1, 100) <= 10 ? 100 : 0,
                'grooming_notes' => self::GROOMING_NOTES[mt_rand(0, count(self::GROOMING_NOTES) - 1)],
                'paid_at' => $when->copy()->addMinutes(90),
            ]);
        } elseif ($status !== AppointmentStatus::Scheduled) {
            $appointment->update(['status' => $status]);
        }

        return $appointment;
    }

    /** A package bought a few weeks ago, partly used. */
    private function package(Collection $pets, Collection $main, Collection $addons): void
    {
        $pet = $pets->first();
        $startsOn = today()->subDays(mt_rand(3, 25));
        $package = Package::sell([
            'pet_ids' => [$pet->getKey()],
            'service_id' => $main->first()->getKey(),
            'addon_ids' => mt_rand(0, 1) && $addons->isNotEmpty() ? [$addons->firstWhere('name', '指甲修剪')?->getKey() ?? $addons->first()->getKey()] : [],
            'payment_method' => PaymentMethod::LinePay,
            'starts_on' => $startsOn,
        ]);
        $package->update(['paid_at' => $startsOn->copy()->setTime(12, 0)]);

        $services = Service::whereKey([$package->service_id, ...$package->addonIds()])->get()->all();

        for ($day = $startsOn->copy(); $day->lt(today()->subDay()); $day = $day->copy()->addDays(7)) {
            $this->visit($pet, $day->copy()->setTime(11, 0), $services, AppointmentStatus::Completed, $package);
        }

        $pet->refreshNextVisit();
    }

    /** About a third of pets have their next visit booked in the coming two weeks. */
    private function upcoming(Pet $pet, Collection $main, Collection $addons, bool $online): void
    {
        if (! $online && mt_rand(1, 100) > 30) {
            return;
        }

        $day = today()->addDays(mt_rand(0, 14));

        if ($day->isToday() && now()->hour >= 16) {
            $day = $day->addDay();
        }

        $when = $day->copy()->setTime(mt_rand(max(10, $day->isToday() ? now()->hour + 1 : 10), 17), mt_rand(0, 1) * 30);
        $appointment = $this->visit($pet, $when, $this->servicesFor($pet, $main, $addons), AppointmentStatus::Scheduled);

        if ($online) {
            $appointment->update([
                'source' => Appointment::SOURCE_ONLINE,
                'groomer_id' => null,
                'booking_code' => $this->bookingCode(),
            ]);
        }
    }

    private function bookingCode(): string
    {
        do {
            $code = collect(range(1, 6))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[mt_rand(0, 31)])->implode('');
        } while (Appointment::where('booking_code', $code)->exists());

        return $code;
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        foreach ([['洗毛精', 350], ['肉乾零食', 120], ['潔牙骨', 180], ['寵物梳', 250], ['耳朵清潔液', 280], ['化毛膏', 320]] as [$name, $price]) {
            Product::firstOrCreate(['name' => $name], ['price' => $price]);
        }

        return Product::where('is_active', true)->get();
    }

    private function productOrder(Customer $customer, Collection $products): void
    {
        $paidAt = now()->subDays(mt_rand(1, 300))->setTime(mt_rand(10, 18), 0);
        $order = ProductOrder::create([
            'customer_id' => $customer->getKey(),
            'payment_method' => $this->pick(['cash' => 70, 'line_pay' => 30]),
            'paid_at' => $paidAt,
        ]);

        foreach ($products->random(mt_rand(1, 2)) as $product) {
            $order->items()->create(['product_id' => $product->getKey(), 'price' => $product->price, 'quantity' => mt_rand(1, 3)]);
        }
    }

    /** A weighted random choice. @param  array<int|string, int>  $weights */
    private function pick(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
