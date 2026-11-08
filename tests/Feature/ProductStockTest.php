<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\StockReason;
use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\VisitReminders;
use App\Filament\Resources\ProductOrders\Pages\CreateProductOrder;
use App\Filament\Resources\ProductOrders\Pages\EditProductOrder;
use App\Filament\Resources\ProductOrders\Pages\ListProductOrders;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Widgets\LowStockProducts;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
        $this->staff = User::factory()->create(['role' => UserRole::Staff, 'is_active' => true]);
        $this->actingAs($this->owner);
    }

    private function product(?int $stock = 10, string $name = '洗毛精'): Product
    {
        return Product::create(['name' => $name, 'price' => 350, 'stock' => $stock]);
    }

    private function sell(Product $product, int $quantity): ProductOrder
    {
        $order = ProductOrder::create(['paid_at' => now(), 'payment_method' => PaymentMethod::Cash]);
        $order->items()->create(['product_id' => $product->id, 'price' => $product->price, 'quantity' => $quantity]);

        return $order;
    }

    /** @return list<array{0: string, 1: int, 2: int}> oldest first */
    private function history(Product $product): array
    {
        return $product->stockMovements()->orderBy('id')->get()
            ->map(fn ($line) => [$line->reason->value, $line->change, $line->balance])
            ->all();
    }

    public function test_a_new_product_with_a_count_starts_its_history(): void
    {
        $this->assertSame([['initial', 10, 10]], $this->history($this->product(10)));
    }

    public function test_a_sale_through_the_order_form_takes_stock_and_records_who_and_which_order(): void
    {
        $product = $this->product(10);
        $this->actingAs($this->staff);

        Livewire::test(CreateProductOrder::class)
            ->fillForm([
                'payment_method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
                'discount' => 0,
                'items' => [['product_id' => $product->id, 'name' => $product->name, 'price' => 350, 'quantity' => 3]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(7, $product->refresh()->stock);

        $line = $product->stockMovements()->latest('id')->first();
        $this->assertSame(StockReason::Sale, $line->reason);
        $this->assertSame(-3, $line->change);
        $this->assertSame($this->staff->id, $line->user_id);
        $this->assertSame(ProductOrder::sole()->id, $line->product_order_id);
    }

    public function test_products_the_shop_does_not_count_are_left_alone(): void
    {
        $product = $this->product(null);

        $this->sell($product, 5);

        $this->assertNull($product->refresh()->stock);
        $this->assertSame([], $this->history($product));
    }

    public function test_selling_more_than_the_count_still_records_the_sale_and_warns(): void
    {
        $product = $this->product(1);

        Livewire::test(CreateProductOrder::class)
            ->fillForm([
                'payment_method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
                'discount' => 0,
                'items' => [['product_id' => $product->id, 'name' => $product->name, 'price' => 350, 'quantity' => 3]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('庫存數字對不上');

        $this->assertSame(1, ProductOrder::count());
        $this->assertSame(-2, $product->refresh()->stock);
    }

    public function test_editing_an_order_moves_only_the_difference(): void
    {
        $product = $this->product(10);
        $order = $this->sell($product, 3);
        $item = $order->items()->first();

        $item->update(['quantity' => 5]);
        $this->assertSame(5, $product->refresh()->stock);

        $item->update(['price' => 1]);
        $this->assertSame(5, $product->refresh()->stock, 'a price change touches no stock');

        $item->update(['quantity' => 1]);
        $this->assertSame(9, $product->refresh()->stock);
    }

    public function test_switching_an_order_line_to_another_product_moves_both(): void
    {
        $shampoo = $this->product(10);
        $brush = $this->product(10, '梳子');
        $item = $this->sell($shampoo, 2)->items()->first();

        $item->update(['product_id' => $brush->id, 'quantity' => 4]);

        $this->assertSame(10, $shampoo->refresh()->stock);
        $this->assertSame(6, $brush->refresh()->stock);
    }

    public function test_removing_a_line_in_the_edit_form_puts_the_goods_back(): void
    {
        $shampoo = $this->product(10);
        $brush = $this->product(10, '梳子');
        $order = $this->sell($shampoo, 2);
        $order->items()->create(['product_id' => $brush->id, 'price' => 100, 'quantity' => 1]);

        $keep = $order->items()->where('product_id', $brush->id)->first();

        Livewire::test(EditProductOrder::class, ['record' => $order->id])
            ->fillForm(['items' => [['id' => $keep->id, 'product_id' => $brush->id, 'name' => '梳子', 'price' => 100, 'quantity' => 1]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(10, $shampoo->refresh()->stock);
        $this->assertSame(9, $brush->refresh()->stock);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_a_refund_returns_goods_only_when_asked(): void
    {
        $product = $this->product(10);
        $returned = $this->sell($product, 2);
        $opened = $this->sell($product, 3);

        Livewire::test(ListProductOrders::class)
            ->callAction(TestAction::make('refund')->table($returned), ['return_to_stock' => true]);
        $this->assertSame(7, $product->refresh()->stock);

        Livewire::test(ListProductOrders::class)
            ->callAction(TestAction::make('refund')->table($opened), ['return_to_stock' => false]);
        $this->assertSame(7, $product->refresh()->stock);
        $this->assertNotNull($opened->refresh()->refunded_at);
    }

    public function test_refunding_twice_does_not_return_goods_twice(): void
    {
        $product = $this->product(10);
        $order = $this->sell($product, 2);

        $order->refund(true);
        $firstRefund = $order->refresh()->refunded_at;
        $this->travel(1)->hours();
        ProductOrder::find($order->id)->refund(true);

        $this->assertSame(10, $product->refresh()->stock);
        $this->assertEquals($firstRefund, $order->refresh()->refunded_at);
    }

    public function test_the_owner_restocks_and_counts_and_history_keeps_how_far_off_it_was(): void
    {
        $product = $this->product(10);

        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($product), ['mode' => 'restock', 'quantity' => 5, 'note' => '廠商到貨'])
            ->assertHasNoActionErrors();
        $this->assertSame(15, $product->refresh()->stock);

        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($product), ['mode' => 'count', 'quantity' => 12])
            ->assertHasNoActionErrors();
        $this->assertSame(12, $product->refresh()->stock);

        $this->assertSame([['initial', 10, 10], ['restock', 5, 15], ['count', -3, 12]], $this->history($product));
        $this->assertSame('廠商到貨', $product->stockMovements()->where('reason', 'restock')->value('note'));

        Livewire::test(ManageProducts::class)
            ->mountAction(TestAction::make('stockHistory')->table($product))
            ->assertMountedActionModalSee('廠商到貨')
            ->assertMountedActionModalSee('盤點');
    }

    public function test_a_count_of_zero_is_allowed_but_receiving_zero_is_not(): void
    {
        $product = $this->product(5);

        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($product), ['mode' => 'restock', 'quantity' => 0])
            ->assertHasActionErrors(['quantity']);
        $this->assertSame(5, $product->refresh()->stock);

        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($product), ['mode' => 'count', 'quantity' => 0])
            ->assertHasNoActionErrors();
        $this->assertSame(0, $product->refresh()->stock);
    }

    public function test_receiving_goods_starts_counting_a_product_that_was_not_counted(): void
    {
        $product = $this->product(null);

        $product->restock(4);

        $this->assertSame(4, $product->refresh()->stock);
        $this->assertSame([['restock', 4, 4]], $this->history($product));
    }

    public function test_staff_can_see_stock_but_not_adjust_it(): void
    {
        $product = $this->product(10);
        $this->actingAs($this->staff);

        Livewire::test(ManageProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertActionHidden(TestAction::make('adjustStock')->table($product));
    }

    public function test_low_stock_shows_on_the_notifications_page_and_not_the_dashboard(): void
    {
        $low = $this->product(3);
        $fine = $this->product(4, '梳子');
        $hidden = Product::create(['name' => '停售', 'price' => 1, 'stock' => 0, 'is_active' => false]);
        $uncounted = $this->product(null, '不追蹤');

        $this->assertEqualsCanonicalizing([$low->id], Product::lowOnStock()->pluck('id')->all());
        $this->assertSame(1, VisitReminders::pendingCount());

        Livewire::test(LowStockProducts::class)
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$fine, $hidden, $uncounted]);

        $this->assertNotContains(LowStockProducts::class, (new Dashboard)->getWidgets());

        $low->restock(10);
        $this->assertSame(0, VisitReminders::pendingCount());
        $this->assertFalse(LowStockProducts::canView());
    }
}
