<?php

namespace App\Filament\Resources\ProductOrders;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Pets\PetResource;
use App\Filament\Resources\ProductOrders\Pages\CreateProductOrder;
use App\Filament\Resources\ProductOrders\Pages\EditProductOrder;
use App\Filament\Resources\ProductOrders\Pages\ListProductOrders;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ExcelExport;
use App\Models\Product;
use App\Models\ProductOrder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductOrderResource extends Resource
{
    protected static ?string $model = ProductOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShoppingBag;

    protected static ?string $navigationLabel = '商品訂單';

    protected static ?string $modelLabel = '商品訂單';

    protected static ?string $pluralModelLabel = '商品訂單';

    protected static ?int $navigationSort = 55;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('訂單資訊')->columns(2)->schema([
                PetResource::customerSelect()->required(false)->helperText('散客可以不填'),
                DateTimePicker::make('paid_at')->label('付款時間')->seconds(false)->default(now())->maxDate(now()->endOfDay())->required(),
                Select::make('payment_method')->label('付款方式')->options(PaymentMethod::class)->default(PaymentMethod::Cash)->required(),
                TextInput::make('discount')->label('折扣金額')->numeric()->integer()->minValue(0)->default(0)->prefix('$')->required()->live(onBlur: true),
                Textarea::make('notes')->label('備註')->rows(2)->columnSpanFull(),
            ]),
            Section::make('商品')->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->relationship()
                    ->columns(3)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->required()
                    ->reorderable(false)
                    ->addActionLabel('再加一項商品')
                    ->live()
                    ->schema([
                        Select::make('product_id')
                            ->label('商品')
                            ->searchable()
                            ->options(fn ($state) => Product::query()
                                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $state))
                                ->orderBy('sort_order')
                                ->pluck('name', 'id'))
                            ->createOptionForm(ProductResource::fields())
                            ->createOptionUsing(fn (array $data) => Product::create($data)->getKey())
                            ->createOptionModalHeading('新增商品')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                $product = Product::find($state);
                                $set('name', $product?->name);
                                $set('price', $product?->price ?? 0);
                            }),
                        TextInput::make('price')->label('單價')->numeric()->integer()->minValue(0)->prefix('$')->required()->live(onBlur: true),
                        TextInput::make('quantity')->label('數量')->numeric()->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                        Hidden::make('name'),
                    ]),
                TextEntry::make('estimate')
                    ->label('實收')
                    ->weight('bold')
                    ->state(function (Get $get) {
                        $subtotal = collect($get('items'))->sum(fn ($item) => (int) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 0));

                        return '$'.number_format(max(0, $subtotal - (int) $get('discount')));
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::columns($table)
            ->headerActions([
                ExcelExport::action('商品訂單', fn (ProductOrder $order) => [
                    '訂單編號' => $order->order_number,
                    '付款時間' => $order->paid_at?->format('Y/m/d H:i'),
                    '客戶' => $order->customer?->name ?? '散客',
                    '商品' => $order->items->map(fn ($item) => "{$item->name} x{$item->quantity}")->implode('、'),
                    '小計' => $order->subtotal,
                    '折扣' => $order->discount,
                    '實收' => $order->total,
                    '付款方式' => $order->payment_method?->getLabel(),
                    '退款時間' => $order->refunded_at?->format('Y/m/d H:i'),
                    '備註' => $order->notes,
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('refund')
                    ->label('退款')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('整筆金額會從今天的營收扣除，原訂單保留。')
                    ->visible(fn (ProductOrder $record) => auth()->user()->can('update', $record))
                    ->action(fn (ProductOrder $record) => $record->update(['refunded_at' => now()])),
            ]);
    }

    /** Columns shared with the customer page. */
    public static function columns(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'items']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->label('訂單編號')->searchable(),
                TextColumn::make('paid_at')->label('付款時間')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('customer.name')
                    ->label('客戶')
                    ->placeholder('散客')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->orWhereHas('customer', fn (Builder $query) => $query->search($search))),
                TextColumn::make('items.name')->label('商品')->badge()->color('gray'),
                TextColumn::make('total')
                    ->label('實收')
                    ->prefix('$')
                    ->numeric(0)
                    ->description(fn (ProductOrder $record) => $record->refunded_at ? '已退款' : null),
                TextColumn::make('payment_method')->label('付款方式')->placeholder('—'),
            ])
            ->searchPlaceholder('搜尋訂單編號、客戶姓名或電話');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOrders::route('/'),
            'create' => CreateProductOrder::route('/create'),
            'edit' => EditProductOrder::route('/{record}/edit'),
        ];
    }
}
