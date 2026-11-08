<?php

namespace App\Filament\Support;

use App\Enums\AppointmentStatus;
use App\Enums\PetTemperament;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\VisitReminders;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Packages\Pages\ManagePackages;
use App\Filament\Resources\Pets\Pages\EditPet;
use App\Filament\Resources\Pets\Pages\ListPets;
use App\Filament\Resources\ProductOrders\Pages\ListProductOrders;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\ProductOrder;
use App\Services\Revenue;
use Illuminate\Support\Carbon;

/**
 * The few numbers shown on the dark band under each page's title, picked for what
 * that page is about, so the band says something useful instead of sitting empty.
 */
class PageInsights
{
    /**
     * @param  list<string>  $scopes  The page's render-hook scopes (its class names).
     * @return list<array{icon: string, label: string, value: string, tone?: string}>
     */
    public static function for(array $scopes): array
    {
        $is = fn (string $page) => in_array($page, $scopes, true);

        return match (true) {
            $is(Dashboard::class) => [],
            $is(ListAppointments::class) => self::appointments(),
            $is(ListCustomers::class) => self::customers(),
            $is(EditCustomer::class) => self::customer(Customer::find(request()->route('record'))),
            $is(ListPets::class) => self::pets(),
            $is(EditPet::class) => self::pet(Pet::find(request()->route('record'))),
            $is(ManagePackages::class) => self::packages(),
            $is(ListProductOrders::class) => self::productOrders(),
            $is(VisitReminders::class) => self::reminders(),
            default => self::today(),
        };
    }

    private static function chip(string $icon, string $label, string|int $value, ?string $tone = null): array
    {
        return array_filter([
            'icon' => $icon,
            'label' => $label,
            'value' => is_int($value) ? number_format($value) : $value,
            'tone' => $tone,
        ], fn ($part) => $part !== null);
    }

    private static function today(): array
    {
        return [
            self::chip('heroicon-o-sun', '今天', today()->locale('zh_TW')->isoFormat('M/D（dd）')),
            self::chip('heroicon-o-calendar-days', '今日預約', self::todaysAppointments()->count()),
            self::chip('heroicon-o-bell-alert', '待處理通知', VisitReminders::pendingCount(), 'warn'),
        ];
    }

    private static function todaysAppointments()
    {
        return Appointment::whereDate('starts_at', today())
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::NoShow]);
    }

    private static function appointments(): array
    {
        $today = self::todaysAppointments();
        $awaiting = Appointment::awaitingConfirmation()->count();

        return array_values(array_filter([
            $awaiting ? self::chip('heroicon-o-globe-alt', '線上待確認', $awaiting, 'warn') : null,
            self::chip('heroicon-o-calendar-days', '今日預約', (clone $today)->count()),
            self::chip('heroicon-o-scissors', '美容中', (clone $today)->where('status', AppointmentStatus::InProgress)->count()),
            self::chip('heroicon-o-clock', '今日未完成', (clone $today)->whereIn('status', AppointmentStatus::active())->count(), 'warn'),
            self::chip('heroicon-o-arrow-right-circle', '明日預約', Appointment::whereDate('starts_at', today()->addDay())
                ->whereIn('status', AppointmentStatus::active())->count()),
        ]));
    }

    private static function customers(): array
    {
        return [
            self::chip('heroicon-o-users', '客戶總數', Customer::count()),
            self::chip('heroicon-o-user-plus', '本月新客', Customer::where('created_at', '>=', now()->startOfMonth())->count()),
            self::chip('heroicon-o-ticket', '有包月的客戶', Package::whereNull('cancelled_at')
                ->whereDate('expires_on', '>=', today())->distinct('customer_id')->count('customer_id')),
        ];
    }

    private static function customer(?Customer $customer): array
    {
        if (! $customer) {
            return self::today();
        }

        $visits = $customer->appointments()->where('status', AppointmentStatus::Completed);
        $lastVisit = (clone $visits)->max('starts_at');

        return array_values(array_filter([
            self::chip('bibi-paw', '寵物', $customer->pets()->pluck('name')->implode('、') ?: '還沒有'),
            self::chip('heroicon-o-check-badge', '到店次數', (clone $visits)->count().' 次'),
            self::chip('heroicon-o-clock', '上次來店', $lastVisit ? Carbon::parse($lastVisit)->format('Y/m/d') : '還沒來過'),
            auth()->user()->isOwner() ? self::chip('heroicon-o-banknotes', '累計消費', '$'.number_format($customer->lifetimeSpending())) : null,
        ]));
    }

    private static function pets(): array
    {
        return [
            self::chip('bibi-paw', '寵物', Pet::where('is_active', true)->count()),
            self::chip('heroicon-o-exclamation-triangle', '會咬人', Pet::where('temperament', PetTemperament::Bites)->count(), 'warn'),
            self::chip('heroicon-o-shield-exclamation', '疫苗已過期', Pet::whereDate('rabies_vaccine_expires_on', '<', today())->count(), 'warn'),
            self::chip('heroicon-o-arrow-path', '該回訪', Pet::dueForReminder()->count()),
        ];
    }

    private static function pet(?Pet $pet): array
    {
        if (! $pet) {
            return self::today();
        }

        $visits = $pet->appointments()->where('status', AppointmentStatus::Completed);
        $lastVisit = (clone $visits)->max('starts_at');
        $package = $pet->packages()->whereNull('cancelled_at')->whereDate('expires_on', '>=', today())->with('pets')->latest('expires_on')->first();

        return array_values(array_filter([
            self::chip('heroicon-o-check-badge', '到店次數', (clone $visits)->count().' 次'),
            self::chip('heroicon-o-clock', '上次來店', $lastVisit ? Carbon::parse($lastVisit)->format('Y/m/d') : '還沒來過'),
            self::chip('heroicon-o-arrow-path', '建議回訪', $pet->next_visit_due_on?->format('Y/m/d') ?? '—'),
            $package ? self::chip('heroicon-o-ticket', '包月剩餘', $package->remainingVisits($pet).' 次') : null,
            $pet->warnings() ? self::chip('heroicon-o-exclamation-triangle', '注意', implode('、', $pet->warnings()), 'warn') : null,
        ]));
    }

    private static function packages(): array
    {
        $active = Package::whereNull('cancelled_at')->whereDate('expires_on', '>=', today())->with('pets')->get();

        return [
            self::chip('heroicon-o-ticket', '使用中', $active->filter(fn (Package $p) => $p->totalRemaining() > 0)->count()),
            self::chip('heroicon-o-calculator', '剩餘總次數', $active->sum(fn (Package $p) => $p->totalRemaining()).' 次'),
            self::chip('heroicon-o-bell-alert', '需要聯絡', count(Package::needingAttention()), 'warn'),
        ];
    }

    private static function productOrders(): array
    {
        $month = ProductOrder::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]);

        return array_values(array_filter([
            self::chip('heroicon-o-shopping-bag', '本月訂單', (clone $month)->count()),
            auth()->user()->isOwner()
                ? self::chip('heroicon-o-banknotes', '本月商品營收', '$'.number_format((int) (clone $month)->whereNull('refunded_at')->sum('total')))
                : null,
            self::chip('heroicon-o-sun', '今日訂單', ProductOrder::whereDate('paid_at', today())->count()),
        ]));
    }

    private static function reminders(): array
    {
        return [
            self::chip('heroicon-o-arrow-path', '該回訪的寵物', Pet::dueForReminder()->count(), 'warn'),
            self::chip('heroicon-o-ticket', '包月需要聯絡', count(Package::needingAttention()), 'warn'),
            self::chip('heroicon-o-banknotes', '本月營收', auth()->user()->isOwner() ? '$'.number_format(Revenue::total(now()->startOfMonth(), now()->endOfDay())) : '—'),
        ];
    }
}
