<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use DomainException;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManagePackages extends ManageRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('購買包月')
                ->modalHeading('購買包月方案')
                ->using(function (array $data, CreateAction $action) {
                    try {
                        return Package::sell($data);
                    } catch (DomainException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }
                }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('使用中')->modifyQueryUsing(fn (Builder $query) => $query
                ->whereNull('cancelled_at')
                ->whereDate('expires_on', '>=', today())),
            'expired' => Tab::make('已逾期')->modifyQueryUsing(fn (Builder $query) => $query
                ->whereNull('cancelled_at')
                ->whereDate('expires_on', '<', today())),
            'all' => Tab::make('全部'),
        ];
    }
}
