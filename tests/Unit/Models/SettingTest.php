<?php

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_values_fall_back_to_the_defaults(): void
    {
        $this->assertSame(9.0, Setting::value('package_discount'));
        $this->assertSame(24.0, Setting::value('member_cancel_hours'));
        $this->assertSame('', Setting::text('shop_phone'));
        $this->assertSame([], Setting::closedWeekdays());
    }

    public function test_numbers_and_words_are_kept_apart(): void
    {
        Setting::store(['booking_open_hour' => '9.5', 'shop_phone' => '02-1234-5678', 'not_a_setting' => 'x']);

        $this->assertSame(9.5, Setting::value('booking_open_hour'));
        $this->assertSame('02-1234-5678', Setting::text('shop_phone'));
        $this->assertArrayNotHasKey('shop_phone', Setting::current(), 'words never show up among the numbers');
        $this->assertArrayNotHasKey('booking_open_hour', Setting::texts());
        $this->assertNull(Setting::find('not_a_setting'), 'unknown keys are ignored');
    }

    public function test_every_number_comes_back_as_a_float(): void
    {
        Setting::store(['package_visits' => '6']);

        foreach (Setting::current() as $key => $value) {
            $this->assertIsFloat($value, $key);
        }

        $this->assertSame(6.0, Setting::current()['package_visits']);
        $this->assertEqualsCanonicalizing(array_keys(Setting::DEFAULTS), array_keys(Setting::current()));
    }

    public function test_days_off_are_saved_from_a_list_and_read_back_as_numbers(): void
    {
        Setting::store(['booking_closed_days' => ['0', '3']]);
        $this->assertSame('0,3', Setting::text('booking_closed_days'));
        $this->assertSame([0, 3], Setting::closedWeekdays(), 'Sunday (0) is a real day off, not "empty"');

        Setting::store(['booking_closed_days' => []]);
        $this->assertSame([], Setting::closedWeekdays());

        Setting::store(['booking_closed_days' => '1,,5']);
        $this->assertSame([1, 5], Setting::closedWeekdays(), 'stray commas are skipped');
    }

    public function test_saving_again_updates_instead_of_duplicating(): void
    {
        Setting::store(['vip_spending' => 5000]);
        Setting::store(['vip_spending' => 8000]);

        $this->assertSame(1, Setting::where('key', 'vip_spending')->count());
        $this->assertSame(8000.0, Setting::value('vip_spending'));
    }
}
