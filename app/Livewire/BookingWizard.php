<?php

namespace App\Livewire;

use App\Enums\PetSize;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use App\Services\OnlineBooking;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The three booking steps on the website: what to do, when, and who's coming. */
class BookingWizard extends Component
{
    public int $step = 1;

    #[Url(as: 'service', except: null)]
    public ?int $serviceId = null;

    /** @var list<int> */
    public array $addonIds = [];

    public string $size = 'small';

    #[Url(except: null)]
    public ?string $date = null;

    public ?string $time = null;

    public string $phone = '';

    public string $name = '';

    public string $petName = '';

    public string $species = 'dog';

    public string $breed = '';

    public string $notes = '';

    /** Left empty by people; bots fill every field. */
    public string $website = '';

    public ?string $bookingCode = null;

    public ?string $bookedAt = null;

    /** One of a signed-in member's own pets; null for a pet the shop hasn't met. */
    #[Url(as: 'pet', except: null)]
    public ?int $petId = null;

    public function mount(): void
    {
        if ($member = $this->member()) {
            $this->phone = (string) $member->phone;
            $this->name = $member->name;
            $pets = $this->myPets;

            $this->choosePet($pets->contains('id', $this->petId) ? $this->petId : ($pets->count() === 1 ? $pets->first()->id : null));
        } else {
            $this->petId = null;
        }

        if ($this->serviceId && ! $this->services->contains('id', $this->serviceId)) {
            $this->serviceId = null;
        }

        if ($this->date && ! collect($this->days)->contains(fn (Carbon $day) => $day->toDateString() === $this->date)) {
            $this->date = null;
        }
    }

    #[Computed]
    public function services(): Collection
    {
        return OnlineBooking::mainServices();
    }

    #[Computed]
    public function addons(): Collection
    {
        return OnlineBooking::addons();
    }

    /** @return list<Carbon> */
    #[Computed]
    public function days(): array
    {
        return OnlineBooking::days();
    }

    /** @return Collection<int, Service> */
    #[Computed]
    public function chosen(): Collection
    {
        return collect([$this->services->firstWhere('id', $this->serviceId)])
            ->merge($this->addons->whereIn('id', $this->addonIds))
            ->filter()
            ->values();
    }

    #[Computed]
    public function minutes(): int
    {
        return OnlineBooking::duration($this->chosen);
    }

    #[Computed]
    public function estimate(): int
    {
        return (int) $this->chosen->sum(fn (Service $service) => $this->priceOf($service));
    }

    /** What this visit would cost: a known pet pays what it paid last time, others the list price. */
    public function priceOf(Service $service): int
    {
        return $this->pet?->priceFor($service) ?? $service->priceFor(PetSize::tryFrom($this->size) ?? PetSize::Small);
    }

    /** The signed-in member, if any. */
    public function member(): ?Customer
    {
        $member = Auth::guard('member')->user();

        return $member?->isMember() ? $member : null;
    }

    /** @return Collection<int, Pet> */
    #[Computed]
    public function myPets(): Collection
    {
        return $this->member()?->pets()->where('is_active', true)->orderBy('id')->get() ?? collect();
    }

    #[Computed]
    public function pet(): ?Pet
    {
        return $this->petId ? $this->myPets->firstWhere('id', $this->petId) : null;
    }

    public function choosePet(?int $id): void
    {
        $pet = $id ? $this->myPets->firstWhere('id', $id) : null;
        $this->petId = $pet?->id;
        unset($this->pet);

        if ($pet) {
            $this->petName = $pet->name;
            $this->species = $pet->species?->value === 'cat' ? 'cat' : 'dog';
            $this->breed = (string) $pet->breed;
            $this->size = $pet->size?->value ?? 'small';
        } else {
            $this->petName = '';
            $this->breed = '';
        }
    }

    /**
     * Start times for the chosen day. Not called "slots": Livewire 4 uses that name itself.
     *
     * @return list<array{time: string, available: bool}>
     */
    #[Computed]
    public function times(): array
    {
        return $this->date ? OnlineBooking::slots(Carbon::parse($this->date), $this->minutes) : [];
    }

    public function chooseService(int $id): void
    {
        $this->serviceId = $id;
        $this->time = null;
    }

    public function toggleAddon(int $id): void
    {
        $this->addonIds = in_array($id, $this->addonIds, true)
            ? array_values(array_diff($this->addonIds, [$id]))
            : [...$this->addonIds, $id];
        $this->time = null;
    }

    public function chooseDate(string $date): void
    {
        $this->date = $date;
        $this->time = null;
    }

    public function chooseTime(string $time): void
    {
        $this->time = $time;
    }

    public function next(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'serviceId' => ['required', Rule::in($this->services->pluck('id'))],
                'size' => ['required', Rule::enum(PetSize::class)],
            ], ['serviceId.required' => '請先選一個美容服務']);
        }

        if ($this->step === 2) {
            $this->validate(['date' => ['required'], 'time' => ['required']], [
                'date.required' => '請選擇日期',
                'time.required' => '請選擇時段',
            ]);

            if (! OnlineBooking::isAvailable(Carbon::parse("{$this->date} {$this->time}"), $this->minutes)) {
                $this->time = null;
                $this->addError('time', '這個時段剛好被約走了，請換一個時間。');

                return;
            }
        }

        $this->step = min(3, $this->step + 1);
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        if ($step < $this->step) {
            $this->resetErrorBag();
            $this->step = $step;
        }
    }

    public function submit(): void
    {
        // A member books as themselves, whatever the browser sends.
        if ($member = $this->member()) {
            $this->phone = (string) $member->phone;
            $this->name = $member->name;

            if ($this->petId !== null) {
                $this->choosePet($this->petId);
            }
        }

        $this->validate([
            'phone' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! preg_match('/^0\d{8,9}$/', preg_replace('/\D/', '', $value))) {
                    $fail('請填寫正確的電話號碼，例如 0912-345-678');
                }
            }],
            'name' => ['required', 'string', 'max:50'],
            'petName' => ['required', 'string', 'max:30'],
            'species' => ['required', Rule::in(['dog', 'cat'])],
            'breed' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [], [
            'phone' => '手機號碼',
            'name' => '飼主姓名',
            'petName' => '毛孩名字',
            'breed' => '品種',
            'notes' => '備註',
        ]);

        if ($this->website !== '') {
            return;
        }

        $key = 'online-booking:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('form', '送出太多次了，請稍後再試或直接來電。');

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $appointment = OnlineBooking::book([
                'phone' => $this->phone,
                'name' => trim($this->name),
                'pet_name' => $this->petName,
                'species' => $this->species,
                'breed' => trim($this->breed) ?: null,
                'size' => $this->size,
                'service_id' => (int) $this->serviceId,
                'addon_ids' => $this->addonIds,
                'starts_at' => "{$this->date} {$this->time}",
                'notes' => $this->notes,
            ]);
        } catch (DomainException $exception) {
            $this->addError('form', $exception->getMessage());

            return;
        }

        $this->bookingCode = $appointment->booking_code;
        $this->bookedAt = $appointment->starts_at->locale('zh_TW')->isoFormat('M/D（dd）HH:mm');
        $this->step = 4;
    }

    public function render()
    {
        return view('livewire.booking-wizard', [
            'enabled' => OnlineBooking::enabled(),
            'sizes' => PetSize::cases(),
        ]);
    }

    /** Short words for the size buttons, so owners don't have to guess. */
    public static function sizeHint(PetSize $size): string
    {
        return match ($size) {
            PetSize::Small => '10 公斤以下',
            PetSize::Medium => '10–20 公斤',
            PetSize::Large => '20 公斤以上',
        };
    }
}
