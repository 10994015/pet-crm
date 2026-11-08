<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_notice_is_linked_from_every_page_and_names_the_shop(): void
    {
        Setting::store(['shop_phone' => '02-1234-5678', 'shop_address' => '台北市測試路 1 號']);

        $this->get('/')->assertOk()->assertSee(route('front.privacy'));

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('隱私權聲明')
            ->assertSee('02-1234-5678')
            ->assertSee('台北市測試路 1 號')
            ->assertSee('您的權利');
    }

    public function test_a_missing_page_gets_the_friendly_error_page(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('找不到這個頁面')->assertSee('回到首頁');
    }

    public function test_a_crash_shows_the_friendly_page_without_leaking_the_cause(): void
    {
        config(['app.debug' => false]);
        Route::get('/boom', fn () => throw new RuntimeException('secret-database-password'));

        $this->get('/boom')
            ->assertStatus(500)
            ->assertSee('系統出了點問題')
            ->assertDontSee('secret-database-password');
    }

    /** These pages show up when the database is down, so they can't be asking it for anything. */
    public function test_error_pages_render_without_touching_the_database(): void
    {
        DB::enableQueryLog();

        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            $this->assertStringContainsString((string) $status, view("errors.{$status}")->render());
        }

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_other_error_codes_fall_back_to_a_generic_page(): void
    {
        config(['app.debug' => false]);
        Route::get('/gone', fn () => throw new HttpException(410));
        Route::get('/bad-gateway', fn () => throw new HttpException(502));

        $this->get('/gone')->assertStatus(410)->assertSee('這個動作無法完成')->assertSee('410');
        $this->get('/bad-gateway')->assertStatus(502)->assertSee('系統暫時無法使用')->assertSee('502');
    }
}
