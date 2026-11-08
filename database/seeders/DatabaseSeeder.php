<?php

namespace Database\Seeders;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** What a new shop starts with: an owner account and a starter price list to edit. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::where('role', UserRole::Owner)->exists()) {
            $password = env('OWNER_PASSWORD') ?: Str::password(14, symbols: false);
            $username = env('OWNER_USERNAME', 'owner');

            User::create([
                'name' => '業主',
                'username' => $username,
                'email' => env('OWNER_EMAIL', 'owner@example.com'),
                'password' => $password,
                'role' => UserRole::Owner,
                'is_groomer' => true,
            ]);

            $this->command?->warn("業主帳號：{$username}　密碼：{$password}（登入後請到個人資料更改）");
        }

        if (Service::exists()) {
            return;
        }

        $catalog = [
            ['洗澡美容', ServiceType::Standard, [
                ['基礎洗澡', 500, 700, 1000, 60],
                ['洗澡＋全身美容', 1000, 1400, 2000, 120],
            ]],
            ['加購護理', ServiceType::Optional, [
                ['局部修剪', 300, 400, 500, 30],
                ['藥浴 SPA', 300, 400, 600, 30],
                ['指甲修剪', 100, 100, 150, 15],
                ['耳朵清潔', 100, 100, 150, 10],
                ['打結處理', 200, 300, 400, 30],
            ]],
        ];

        foreach ($catalog as [$categoryName, $type, $services]) {
            $category = ServiceCategory::create(['name' => $categoryName]);

            foreach ($services as [$name, $small, $medium, $large, $minutes]) {
                Service::create([
                    'service_category_id' => $category->getKey(),
                    'type' => $type,
                    'name' => $name,
                    'price_small' => $small,
                    'price_medium' => $medium,
                    'price_large' => $large,
                    'duration_minutes' => $minutes,
                ]);
            }
        }
    }
}
