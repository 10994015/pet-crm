<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\TimeOff;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

class DailySchedule extends Page
{
    protected string $view = 'filament.pages.daily-schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Clock;

    protected static ?string $navigationLabel = '行程表';

    protected static ?string $title = '行程表';

    protected static ?int $navigationSort = 20;

    #[Url]
    public string $date = '';

    public function mount(): void
    {
        $this->date = $this->day()->toDateString();
    }

    public function shiftDays(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
    }

    public function goToToday(): void
    {
        $this->date = today()->toDateString();
    }

    public function day(): Carbon
    {
        try {
            return Carbon::parse($this->date ?: today())->startOfDay();
        } catch (Throwable) {
            return today();
        }
    }

    /** @return Collection<int, array{name: string, appointments: Collection<int, Appointment>}> */
    public function columns(): Collection
    {
        $appointments = Appointment::with(['pet.customer', 'items'])
            ->whereDate('starts_at', $this->day())
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('groomer_id');

        // Includes former staff who still have appointments on this day.
        $groomers = User::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('is_active', true)->where('is_groomer', true))
                ->orWhereIn('id', $appointments->keys()->filter()))
            ->orderBy('name')
            ->get();

        $away = TimeOff::query()->on($this->day())->whereNotNull('user_id')->pluck('user_id');

        $columns = $groomers->map(fn (User $groomer) => [
            'name' => $groomer->name,
            'away' => $away->contains($groomer->getKey()),
            'appointments' => $appointments->get($groomer->getKey(), collect()),
        ]);

        if ($appointments->has('')) {
            $columns->prepend(['name' => '尚未指定', 'away' => false, 'appointments' => $appointments->get('')]);
        }

        return $columns->values();
    }

    public function shopClosure(): ?TimeOff
    {
        return TimeOff::forShop()->on($this->day())->first();
    }

    public function editUrl(Appointment $appointment): ?string
    {
        return auth()->user()->can('update', $appointment)
            ? AppointmentResource::getUrl('edit', ['record' => $appointment])
            : null;
    }

    public function createUrl(): string
    {
        return AppointmentResource::getUrl('create', ['date' => $this->day()->toDateString()]);
    }
}
