<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Setting;
use App\Services\OnlineBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FrontController extends Controller
{
    public function home(): View
    {
        return view('front.home', [
            'services' => OnlineBooking::mainServices(),
            'days' => array_slice(OnlineBooking::days(), 0, 14),
            'package' => Setting::current(),
            'structuredData' => $this->structuredData(),
        ]);
    }

    /** Tells search engines this is a local shop, with its contact details and opening hours. */
    private function structuredData(): array
    {
        $shop = Setting::texts();
        $settings = Setting::current();
        $time = fn (float $hour) => sprintf('%02d:%02d', intdiv((int) round($hour * 60), 60), (int) round($hour * 60) % 60);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => config('app.name'),
            'url' => route('front.home'),
            'image' => asset('images/og-image.png'),
            'telephone' => $shop['shop_phone'] ?: null,
            'address' => $shop['shop_address'] ?: null,
            'openingHoursSpecification' => [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_values(array_diff_key($weekdays, array_flip(Setting::closedWeekdays()))),
                'opens' => $time($settings['booking_open_hour']),
                'closes' => $time($settings['booking_close_hour']),
            ]],
        ]);
    }

    public function sitemap(): Response
    {
        return response()
            ->view('front.sitemap', ['urls' => [route('front.home'), route('front.booking'), route('front.privacy')]])
            ->header('Content-Type', 'application/xml');
    }

    /** A test site must not end up in search results next to the real one. */
    public function robots(): Response
    {
        $rules = app()->isProduction()
            ? "User-agent: *\nDisallow: /admin\nDisallow: /livewire\n\nSitemap: ".route('front.sitemap')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($rules)->header('Content-Type', 'text/plain');
    }

    public function booking(): View
    {
        return view('front.booking');
    }

    public function privacy(): View
    {
        return view('front.privacy', ['shop' => Setting::texts()]);
    }

    public function myBooking(): View
    {
        return view('front.my-booking', ['appointment' => null, 'upcoming' => collect()]);
    }

    public function findBooking(Request $request): View
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:12'],
        ], [], ['phone' => '手機號碼', 'code' => '預約代碼']);

        $appointment = OnlineBooking::find($data['phone'], $data['code']);

        if (! $appointment) {
            return view('front.my-booking', ['appointment' => null, 'upcoming' => collect()])
                ->withErrors(['code' => '找不到這筆預約，請確認手機號碼和預約代碼。']);
        }

        // Holding the phone and a code proves it's the family, so their other upcoming visits are shown too.
        $upcoming = $appointment->pet->customer->appointments()
            ->whereKeyNot($appointment->getKey())
            ->whereIn('status', AppointmentStatus::active())
            ->where('starts_at', '>=', today())
            ->with(['pet', 'items'])
            ->orderBy('starts_at')
            ->get();

        return view('front.my-booking', compact('appointment', 'upcoming'));
    }
}
