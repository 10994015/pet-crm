<?php

namespace Tests\Unit\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PackageStatus;
use App\Enums\PetSize;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    private Service $bath;

    private Service $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
        $this->bath = Service::create(['name' => '洗澡', 'price_small' => 500, 'price_medium' => 700, 'price_large' => 1000, 'duration_minutes' => 60]);
        $this->nails = Service::create(['name' => '指甲', 'type' => 'optional', 'price_small' => 100, 'price_medium' => 100, 'price_large' => 150, 'duration_minutes' => 15]);
    }

    private function pet(PetSize $size = PetSize::Small, string $name = '豆豆'): Pet
    {
        return Customer::firstOrCreate(['phone' => '0911000000'], ['name' => '客人'])->pets()->create(['name' => $name, 'size' => $size]);
    }

    public function test_the_quote_discounts_the_main_service_but_not_the_add_ons(): void
    {
        // 500 × 4 × 0.9 = 1800, plus nails 100 × 4 at full price.
        $this->assertSame(2200, Package::quote(collect([$this->pet()]), $this->bath, collect([$this->nails]), 4, 9));
        $this->assertSame(2000, Package::quote(collect([$this->pet()]), $this->bath, collect(), 4, 10), '10 means no discount');
    }

    public function test_the_quote_rounds_each_dog_and_adds_them_up(): void
    {
        $pets = collect([$this->pet(PetSize::Small, 'A'), $this->pet(PetSize::Large, 'B')]);

        // Small: 500 × 3 × 0.85 = 1275; large: 1000 × 3 × 0.85 = 2550.
        $this->assertSame(3825, Package::quote($pets, $this->bath, collect(), 3, 8.5));
        // 333 × 1 × 0.9 = 299.7 → 300.
        $odd = Service::create(['name' => '奇數', 'price_small' => 333, 'price_medium' => 333, 'price_large' => 333]);
        $this->assertSame(300, Package::quote(collect([$this->pet()]), $odd, collect(), 1, 9));
    }

    public function test_covers_the_main_service_and_the_prepaid_add_ons_only(): void
    {
        $package = new Package(['service_id' => $this->bath->id, 'included_addons' => [['id' => (string) $this->nails->id, 'name' => '指甲']]]);
        $package->service_id = $this->bath->id;

        $this->assertSame([$this->nails->id], $package->addonIds(), 'ids stored as text still compare as numbers');
        $this->assertTrue($package->covers($this->bath->id));
        $this->assertTrue($package->covers($this->nails->id));
        $this->assertFalse($package->covers(999));
        $this->assertSame([], (new Package)->addonIds());
    }

    public function test_extending_counts_from_the_end_date_or_from_today_if_lapsed(): void
    {
        $package = Package::sell(['pet_ids' => [$this->pet()->id], 'service_id' => $this->bath->id]);

        $package->update(['expires_on' => '2026-11-30']);
        $package->extend(1);
        $this->assertSame('2026-12-30', $package->expires_on->toDateString());

        $package->update(['expires_on' => '2026-10-01']);
        $package->extend(1);
        $this->assertSame('2026-12-10', $package->expires_on->toDateString(), 'a lapsed package restarts from today');

        $package->update(['expires_on' => '2027-01-31']);
        $package->extend(1);
        $this->assertSame('2027-02-28', $package->expires_on->toDateString(), 'no overflow into March');
    }

    public function test_status_order_cancelled_then_used_up_then_expired(): void
    {
        $pet = $this->pet();
        $package = Package::sell(['pet_ids' => [$pet->id], 'service_id' => $this->bath->id]);
        $this->assertSame(PackageStatus::Active, $package->status());

        $package->update(['expires_on' => today()->subDay()]);
        $this->assertSame(PackageStatus::Expired, $package->status());

        $package->adjustUsedVisits($pet, $package->totalVisits($pet), '測試');
        $this->assertSame(PackageStatus::UsedUp, $package->refresh()->status(), 'used up wins over expired');

        $package->update(['cancelled_at' => now()]);
        $this->assertSame(PackageStatus::Cancelled, $package->status());
    }

    public function test_upcoming_bookings_hold_visits_without_using_them(): void
    {
        $pet = $this->pet();
        $package = Package::sell(['pet_ids' => [$pet->id], 'service_id' => $this->bath->id]);
        $visit = Appointment::create(['pet_id' => $pet->id, 'package_id' => $package->id, 'starts_at' => now()->addDay()]);

        $this->assertSame(4, $package->remainingVisits($pet));
        $this->assertSame(3, $package->availableVisits($pet));
        $this->assertSame(4, $package->availableVisits($pet, ignoreAppointmentId: $visit->id), 'editing that booking does not count against itself');

        $visit->update(['status' => AppointmentStatus::Cancelled]);
        $this->assertSame(4, $package->availableVisits($pet), 'cancelling gives the visit back');
    }
}
