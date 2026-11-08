<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_describe_themselves_for_search_and_sharing(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('property="og:title" content="寵物美容線上預約 ｜ '.config('app.name').'"', false)
            ->assertSee('property="og:image" content="'.asset('images/og-image.png').'"', false)
            ->assertSee('name="twitter:card"', false)
            ->assertDontSee('name="robots"', false);

        $this->get('/privacy')
            ->assertSee('<title>隱私權聲明 ｜ '.config('app.name').'</title>', false)
            ->assertSee('name="description" content="說明本店如何蒐集', false);

        $this->assertFileExists(public_path('images/og-image.png'));
    }

    public function test_query_strings_do_not_make_duplicate_pages(): void
    {
        $this->get('/booking?service=1&date=2026-12-01')
            ->assertSee('<link rel="canonical" href="'.url('/booking').'">', false);
    }

    public function test_personal_pages_stay_out_of_search_results(): void
    {
        $this->get('/my-booking')->assertSee('<meta name="robots" content="noindex">', false);
        $this->get('/member/login')->assertSee('<meta name="robots" content="noindex">', false);
        $this->get('/member/activate')->assertSee('<meta name="robots" content="noindex">', false);

        $member = Customer::create(['name' => '會員', 'phone' => '0912345678']);
        $member->activate('secret123');
        $this->actingAs($member, 'member')->get('/member')
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('rel="canonical"', false);
    }

    public function test_the_home_page_tells_search_engines_about_the_shop(): void
    {
        Setting::store([
            'shop_phone' => '02-1234-5678',
            'shop_address' => '台北市測試路 1 號',
            'booking_closed_days' => ['1'],
            'booking_open_hour' => 9.5,
            'booking_close_hour' => 18,
        ]);

        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('#<script type="application/ld\+json">(.+?)</script>#s', $html);
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $found);
        $data = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('LocalBusiness', $data['@type']);
        $this->assertSame('02-1234-5678', $data['telephone']);
        $this->assertSame('台北市測試路 1 號', $data['address']);

        $hours = $data['openingHoursSpecification'][0];
        $this->assertSame('09:30', $hours['opens']);
        $this->assertSame('18:00', $hours['closes']);
        $this->assertNotContains('Monday', $hours['dayOfWeek']);
        $this->assertContains('Sunday', $hours['dayOfWeek']);
    }

    public function test_structured_data_leaves_out_what_is_not_filled_in(): void
    {
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $this->get('/')->getContent(), $found);
        $data = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('telephone', $data);
        $this->assertArrayNotHasKey('address', $data);
    }

    public function test_shop_text_cannot_break_out_of_the_structured_data(): void
    {
        Setting::store(['shop_address' => '</script><script>alert(1)</script>']);

        $this->get('/')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_sitemap_lists_public_pages_only(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertNotFalse(simplexml_load_string($response->getContent()), 'valid XML');

        $response->assertSee(url('/booking'), false)
            ->assertSee(url('/privacy'), false)
            ->assertDontSee('/member', false)
            ->assertDontSee('/admin', false)
            ->assertDontSee('/my-booking', false);
    }

    public function test_robots_points_to_the_sitemap_in_production_and_hides_any_other_site(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /')->assertDontSee('Sitemap:');

        $this->app['env'] = 'production';

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Sitemap: '.url('/sitemap.xml'), false);

        $this->assertStringContainsString('text/plain', $this->get('/robots.txt')->headers->get('Content-Type'));
    }
}
