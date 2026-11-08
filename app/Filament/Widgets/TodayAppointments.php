<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

class TodayAppointments extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 7];

    public function table(Table $table): Table
    {
        return $table
            ->query(Appointment::query()
                ->with(['pet.customer', 'items'])
                ->whereDate('starts_at', today())
                ->where('status', '!=', AppointmentStatus::Cancelled))
            ->defaultSort('starts_at')
            ->heading(new HtmlString('<span class="bibi-card-title">'.svg('heroicon-s-calendar-days')->toHtml().'今日預約</span>'))
            ->paginated(false)
            ->emptyStateHeading('今天還沒有預約')
            ->headerActions([
                Action::make('all')
                    ->label('查看全部')
                    ->link()
                    ->color('gray')
                    ->url(AppointmentResource::getUrl('index')),
            ])
            ->columns([
                Split::make([
                    TextColumn::make('starts_at')->time('H:i')->weight('bold')->grow(false),
                    ImageColumn::make('pet.photo')
                        ->disk('public')
                        ->circular()
                        ->imageSize(44)
                        ->defaultImageUrl(asset('images/brand/pet-placeholder.svg'))
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('pet.name')->weight('bold'),
                        TextColumn::make('services')
                            ->state(fn (Appointment $record) => $record->items->pluck('name')->implode(' + '))
                            ->color('gray')
                            ->size('sm'),
                        TextColumn::make('warnings')
                            ->state(fn (Appointment $record) => implode('、', $record->pet->warnings()) ?: null)
                            ->color('danger')
                            ->size('sm'),
                    ]),
                    TextColumn::make('status')->badge()->grow(false),
                ]),
            ])
            ->recordActions([AppointmentActions::grouped()]);
    }
}
