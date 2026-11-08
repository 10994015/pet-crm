<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Shown on the notifications page, not the dashboard. */
class PackagesNeedingAttention extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $reasons = Package::needingAttention();

        return PackageResource::columns($table)
            ->query(Package::query()->whereKey(array_keys($reasons)))
            ->pushColumns([
                TextColumn::make('reason')
                    ->label('原因')
                    ->badge()
                    ->color(fn (string $state) => $state === '還沒約下一次' ? 'info' : 'danger')
                    ->state(fn (Package $record) => $reasons[$record->getKey()] ?? null),
            ])
            ->heading('包月方案需要聯絡')
            ->description('還有剩餘次數，但快到期、已逾期 30 天內，或有狗還沒約下一次。')
            ->searchable(false)
            ->emptyStateHeading('目前沒有需要聯絡的包月客人')
            ->recordActions([
                Action::make('line')
                    ->label('LINE 傳送')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->url(fn (Package $record) => 'https://line.me/R/share?text='.rawurlencode($record->reminderMessage()))
                    ->openUrlInNewTab(),
                PackageResource::bookAction(),
                ActionGroup::make([PackageResource::extendAction(), PackageResource::adjustAction()]),
            ]);
    }
}
