<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Setting;
use App\Services\OnlineBooking;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FrontController extends Controller
{
    public function home(): View
    {
        return view('front.home', [
            'services' => OnlineBooking::mainServices(),
            'days' => array_slice(OnlineBooking::days(), 0, 14),
            'package' => Setting::current(),
        ]);
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
