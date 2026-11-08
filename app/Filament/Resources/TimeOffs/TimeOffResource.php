<?php

namespace App\Filament\Resources\TimeOffs;

use App\Filament\Resources\TimeOffs\Pages\ManageTimeOffs;
use App\Models\TimeOff;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimeOffResource extends Resource
{
    protected static ?string $model = TimeOff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    protected static ?string $navigationLabel = '店休與請假';

    protected static ?string $modelLabel = '店休或請假';

    protected static ?string $pluralModelLabel = '店休與請假';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('user_id')
                ->label('對象')
                ->placeholder('全店店休')
                ->helperText('不選就是全店店休，線上預約會關閉這幾天；選美容師則是那天少一位可接的人。')
                ->options(fn () => User::activeGroomers()->pluck('name', 'id'))
                ->columnSpanFull(),
            DatePicker::make('starts_on')
                ->label('開始日期')
                ->native(false)
                ->displayFormat('Y/m/d')
                ->default(today())
                ->required()
                ->live(),
            DatePicker::make('ends_on')
                ->label('結束日期')
                ->native(false)
                ->displayFormat('Y/m/d')
                ->default(today())
                ->required()
                ->minDate(fn (Get $get) => $get('starts_on')),
            TextInput::make('reason')->label('原因')->placeholder('例如：過年、員工旅遊')->maxLength(100)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('groomer.name')->label('對象')->weight('bold')->placeholder('全店店休'),
                TextColumn::make('starts_on')->label('開始')->date('Y/m/d')->sortable(),
                TextColumn::make('ends_on')->label('結束')->date('Y/m/d')->sortable(),
                TextColumn::make('reason')->label('原因')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->after(fn (TimeOff $record) => self::warnAboutBookings($record)),
                DeleteAction::make(),
            ]);
    }

    /** Taking days off doesn't cancel what's already booked, so the owner has to be told. */
    public static function warnAboutBookings(TimeOff $timeOff): void
    {
        $count = $timeOff->affectedAppointments();

        if ($count > 0) {
            Notification::make()
                ->title("這段期間還有 {$count} 筆預約")
                ->body('系統不會自動取消，請到行程表確認，並聯絡客人改期。')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageTimeOffs::route('/')];
    }
}
