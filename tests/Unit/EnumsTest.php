<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetGender;
use App\Enums\PetSize;
use App\Enums\PetSpecies;
use App\Enums\PetTemperament;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Livewire\BookingWizard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public static function enums(): array
    {
        return array_map(fn ($class) => [$class], [
            'appointment status' => AppointmentStatus::class,
            'package status' => PackageStatus::class,
            'payment method' => PaymentMethod::class,
            'gender' => PetGender::class,
            'size' => PetSize::class,
            'species' => PetSpecies::class,
            'temperament' => PetTemperament::class,
            'service type' => ServiceType::class,
            'role' => UserRole::class,
        ]);
    }

    /** A case added without a label would show up blank, or crash the screen. */
    #[DataProvider('enums')]
    public function test_every_case_has_a_label(string $enum): void
    {
        $labels = array_map(fn ($case) => method_exists($case, 'getLabel') ? $case->getLabel() : $case->name, $enum::cases());

        foreach ($labels as $label) {
            $this->assertNotSame('', trim((string) $label));
        }

        $this->assertSame(count($labels), count(array_unique($labels)), 'two cases share a label');
    }

    public function test_active_appointments_are_the_ones_holding_a_groomer(): void
    {
        $this->assertSame([AppointmentStatus::Scheduled, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], AppointmentStatus::active());

        foreach (AppointmentStatus::cases() as $status) {
            $this->assertSame(in_array($status, AppointmentStatus::active(), true), $status->isActive(), $status->name);
        }
    }

    public function test_every_size_button_has_a_weight_hint(): void
    {
        $this->assertSame(['10 公斤以下', '10–20 公斤', '20 公斤以上'], array_map(BookingWizard::sizeHint(...), PetSize::cases()));
    }
}
