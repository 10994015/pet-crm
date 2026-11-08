<?php

namespace App\Filament\Resources\Appointments\Pages\Concerns;

use App\Enums\AppointmentStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/** Lets the service tiles on the appointment form add and remove items. */
trait PicksServices
{
    public function toggleService(int $serviceId): void
    {
        $service = Service::findOrFail($serviceId);
        $items = $this->data['items'] ?? [];
        $key = collect($items)->search(fn (array $item) => (int) ($item['service_id'] ?? 0) === $serviceId);

        if ($key !== false) {
            unset($items[$key]);
        } else {
            // One visit has one main service; picking another replaces it.
            if ($service->type === ServiceType::Standard) {
                $standardIds = Service::where('type', ServiceType::Standard)->pluck('id')->all();
                $items = array_filter($items, fn (array $item) => ! in_array((int) ($item['service_id'] ?? 0), $standardIds, true));
            }

            $items[(string) Str::uuid()] = AppointmentResource::itemState(
                $service,
                $this->data['pet_id'] ?? null,
                $this->data['package_id'] ?? null,
            );
        }

        $this->data['items'] = $items;
    }

    public function copyLastServices(): void
    {
        $last = Appointment::query()
            ->where('pet_id', $this->data['pet_id'] ?? 0)
            ->where('status', AppointmentStatus::Completed)
            ->latest('starts_at')
            ->first();

        if (! $last) {
            Notification::make()->title('這隻寵物還沒有消費紀錄')->warning()->send();

            return;
        }

        $this->data['items'] = Service::query()
            ->whereIn('id', $last->items()->pluck('service_id'))
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Service $service) => [
                (string) Str::uuid() => AppointmentResource::itemState(
                    $service,
                    $this->data['pet_id'],
                    $this->data['package_id'] ?? null,
                ),
            ])
            ->all();
    }
}
