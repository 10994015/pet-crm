<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $navigationLabel = '操作紀錄';

    protected static ?string $modelLabel = '操作紀錄';

    protected static ?string $pluralModelLabel = '操作紀錄';

    protected static ?int $navigationSort = 75;

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('id', 'desc')
            ->description('誰在什麼時候改了什麼，保留 '.ActivityLog::KEEP_DAYS.' 天，無法修改或刪除。')
            ->searchPlaceholder('搜尋操作者或對象')
            ->emptyStateHeading('還沒有操作紀錄')
            ->columns([
                TextColumn::make('created_at')->label('時間')->dateTime('Y/m/d H:i:s')->sortable(),
                TextColumn::make('actor')->label('操作者')->weight('bold')->searchable(),
                TextColumn::make('action')
                    ->label('動作')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ActivityLog::ACTIONS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'password_reset' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('subject_type')
                    ->label('類型')
                    ->formatStateUsing(fn (string $state) => ActivityLog::SUBJECTS[$state] ?? $state),
                TextColumn::make('subject_label')->label('對象')->placeholder('—')->searchable(),
                TextColumn::make('details')
                    ->label('內容')
                    ->state(fn (ActivityLog $record) => $record->lines())
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('ip_address')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('員工')->relationship('user', 'name'),
                SelectFilter::make('action')->label('動作')->options(ActivityLog::ACTIONS),
                SelectFilter::make('subject_type')->label('類型')->options(ActivityLog::SUBJECTS),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('從'),
                        DatePicker::make('until')->label('到'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
