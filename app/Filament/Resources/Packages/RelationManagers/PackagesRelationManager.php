<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Filament\Resources\Packages\PackageResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Packages shown on the customer and pet pages. */
class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = '包月方案';

    protected static ?string $modelLabel = '包月方案';

    public function table(Table $table): Table
    {
        return PackageResource::columns($table)
            ->searchable(false)
            ->recordActions([ActionGroup::make([
                PackageResource::editAction(),
                PackageResource::adjustAction(),
                PackageResource::extendAction(),
                PackageResource::terminateAction(),
            ])]);
    }
}
