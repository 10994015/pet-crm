<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?string $navigationLabel = '員工帳號';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = '員工帳號';

    protected static ?string $pluralModelLabel = '員工帳號';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        // Owners can't change their own role or status, so the shop always keeps an active owner.
        $isSelf = fn (?User $record) => $record?->is(auth()->user()) ?? false;

        return $schema->columns(2)->components([
            TextInput::make('name')->label('姓名')->required()->maxLength(100),
            TextInput::make('username')
                ->label('登入帳號')
                ->required()
                ->maxLength(50)
                ->alphaDash()
                ->unique(ignoreRecord: true),
            TextInput::make('email')->label('電子郵件')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label('密碼')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? '留白表示不更改' : null),
            Select::make('role')
                ->label('權限')
                ->options(UserRole::class)
                ->default(UserRole::Staff)
                ->required()
                ->disabled($isSelf),
            Toggle::make('is_groomer')->label('美容師（可被指派預約）')->default(true),
            Toggle::make('is_active')->label('啟用帳號')->default(true)->disabled($isSelf),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')->label('姓名')->weight('bold')->searchable(),
                TextColumn::make('username')->label('登入帳號')->placeholder('—')->searchable(),
                TextColumn::make('email')->label('電子郵件')->searchable(),
                TextColumn::make('role')->label('權限')->badge(),
                IconColumn::make('is_groomer')->label('美容師')->boolean(),
                IconColumn::make('is_active')->label('啟用')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
