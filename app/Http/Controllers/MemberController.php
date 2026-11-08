<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The website's member area. An owner opens it with a code the shop gives them, so a
 * phone number alone never shows anyone else's pets or visits.
 */
class MemberController extends Controller
{
    private function member(): Customer
    {
        return Auth::guard('member')->user();
    }

    public function showLogin(): View|RedirectResponse
    {
        return Auth::guard('member')->check() ? redirect()->route('front.member.home') : view('front.member.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:100'],
        ], [], ['phone' => '手機號碼', 'password' => '密碼']);

        $signedIn = Auth::guard('member')->attempt([
            'phone' => Customer::normalizePhone($data['phone']),
            'password' => $data['password'],
        ], $request->boolean('remember'));

        if (! $signedIn) {
            return back()->withInput($request->only('phone', 'remember'))
                ->withErrors(['phone' => '手機號碼或密碼不對。第一次使用請先「開通會員」。']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('front.member.home'));
    }

    public function showActivate(): View
    {
        return view('front.member.activate');
    }

    public function activate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', Password::min(6)->letters()->numbers()],
            'privacy' => ['accepted'],
        ], [
            'password.confirmed' => '兩次輸入的密碼不一樣',
            'privacy.accepted' => '請先閱讀並勾選同意隱私權聲明',
        ], ['phone' => '手機號碼', 'code' => '開通碼', 'password' => '密碼']);

        $customer = Customer::firstWhere('phone', Customer::normalizePhone($data['phone']));

        if (! $customer?->activationCodeMatches($data['code'])) {
            return back()->withInput($request->only('phone'))
                ->withErrors(['code' => '開通碼不對或已過期，請跟店家索取新的開通碼。']);
        }

        $isNew = ! $customer->isMember();
        $customer->activate($data['password']);
        $customer->acceptPrivacy();

        Auth::guard('member')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('front.member.home')
            ->with('status', $isNew ? '會員開通完成，歡迎加入！' : '密碼已重新設定。');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('front.home');
    }

    public function home(): View
    {
        $member = $this->member();

        $pets = $member->pets()
            ->where('is_active', true)
            ->withMax(['appointments as last_visit_at' => fn ($query) => $query->where('status', AppointmentStatus::Completed)], 'starts_at')
            ->orderBy('id')
            ->get();

        $upcoming = $member->appointments()
            ->whereIn('status', AppointmentStatus::active())
            ->where('starts_at', '>=', today())
            ->with(['pet', 'items'])
            ->orderBy('starts_at')
            ->get();

        $packages = $member->packages()
            ->whereNull('cancelled_at')
            ->whereDate('expires_on', '>=', today())
            ->with('pets')
            ->latest('expires_on')
            ->get()
            ->filter(fn (Package $package) => $package->totalRemaining() > 0)
            ->values();

        $visits = $member->appointments()->where('status', AppointmentStatus::Completed)->count();

        return view('front.member.home', compact('member', 'pets', 'upcoming', 'packages', 'visits'));
    }

    public function history(): View
    {
        $visits = $this->member()->appointments()
            ->where('status', AppointmentStatus::Completed)
            ->with(['pet', 'items'])
            ->latest('starts_at')
            ->paginate(10);

        return view('front.member.history', ['member' => $this->member(), 'visits' => $visits]);
    }

    public function profile(): View
    {
        return view('front.member.profile', ['member' => $this->member()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'backup_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'line_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['backup_phone' => '備用電話', 'email' => '電子郵件', 'line_id' => 'LINE ID', 'address' => '地址']);

        $this->member()->update($data);

        return back()->with('status', '資料已更新。');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $member = $this->member();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(6)->letters()->numbers()],
        ], ['password.confirmed' => '兩次輸入的密碼不一樣'], ['current_password' => '目前的密碼', 'password' => '新密碼']);

        if (! Hash::check($data['current_password'], $member->password)) {
            return back()->withErrors(['current_password' => '目前的密碼不對。']);
        }

        $member->forceFill(['password' => $data['password']])->save();

        return back()->with('status', '密碼已更新。');
    }

    public function cancel(Appointment $appointment): RedirectResponse
    {
        abort_unless($appointment->pet()->where('customer_id', $this->member()->getKey())->exists(), 404);

        if (! $appointment->cancellableByOwner()) {
            return back()->with('error', '這筆預約已經不能在網站上取消了，請直接來電。');
        }

        $appointment->cancelByOwner();

        return back()->with('status', "已取消 {$appointment->pet->name} ".$appointment->starts_at->format('n/j H:i').' 的預約。');
    }
}
