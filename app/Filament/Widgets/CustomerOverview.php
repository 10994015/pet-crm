<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerOverview extends Widget
{
    protected string $view = 'filament.widgets.customer-overview';

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 5];

    /** @return Collection<int, Customer> The customers who visited most recently. */
    public function customers(): Collection
    {
        $completed = fn (Builder $query) => $query->where('status', AppointmentStatus::Completed);

        return Customer::query()
            ->with('pets')
            ->withMax(['appointments as last_visit_at' => $completed], 'starts_at')
            ->whereHas('appointments', $completed)
            ->orderByDesc('last_visit_at')
            ->limit(4)
            ->get();
    }

    public function indexUrl(): string
    {
        return CustomerResource::getUrl('index');
    }

    public function customerUrl(Customer $customer): string
    {
        return CustomerResource::getUrl('edit', ['record' => $customer]);
    }
}
