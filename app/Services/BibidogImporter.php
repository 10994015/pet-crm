<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PetSize;
use App\Enums\PetSpecies;
use App\Enums\PetTemperament;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\ProductOrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies the old bibidog database into this system. It only ever reads from the old
 * database, and running it again skips what was already copied.
 */
class BibidogImporter
{
    /** The old system recorded a visit's date but not its time. */
    private const VISIT_HOUR = 10;

    /** Pets overdue by longer than this are treated as past customers, not reminders. */
    private const REMINDER_CUTOFF_DAYS = 90;

    /** The old system marked these "standard" too, but they are extras on top of a main service. */
    private const ADD_ON_CATEGORIES = ['SPA', '加價修', '額外服務', '接送'];

    /** Every old package was four visits. */
    private const PACKAGE_VISITS = 4;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $issues = [];

    /** @var array<int, Service> Old service id => the merged service it became. */
    private array $services = [];

    /** @var array<int, int> Old service id => its old price. */
    private array $oldPrices = [];

    public function __construct(private ConnectionInterface $legacy) {}

    /** @return array{counts: array<string, int>, issues: list<string>, reconciliation: array<string, int>} */
    public function run(bool $dryRun = false): array
    {
        DB::beginTransaction();

        try {
            $this->importUsers();
            $this->importServices();
            $this->importCustomers();
            $this->importPets();
            $this->importOrders();
            $this->importProductOrders();
            $this->refreshReminders();

            $report = [
                'counts' => $this->counts,
                'issues' => $this->issues,
                'reconciliation' => $this->reconcile(),
            ];

            $dryRun ? DB::rollBack() : DB::commit();

            return $report;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    /** Blank strings and stray whitespace in the old data become nothing. */
    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Password hashes carry over as they are, so staff keep their current passwords. */
    private function importUsers(): void
    {
        foreach ($this->legacy->table('users')->get() as $user) {
            $exists = DB::table('users')
                ->where('email', $user->email)
                ->when($user->username ?? null, fn ($query, $username) => $query->orWhere('username', $username))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('users')->insert([
                'name' => $user->name,
                'username' => $user->username ?? null,
                'email' => $user->email,
                'password' => $user->password,
                'role' => ($user->is_admin ?? false) ? UserRole::Owner->value : UserRole::Staff->value,
                'is_groomer' => false,
                'is_active' => true,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => now(),
            ]);

            $this->count('員工帳號');
        }
    }

    /**
     * The old system kept one service per price ("洗澡" at $500, $550, $600…) because
     * each dog has its own price. Those merge into one service here, and the dog's own
     * price is remembered from its past visits instead.
     */
    private function importServices(): void
    {
        $categories = $this->legacy->table('service_categories')->orderBy('sort_order')->get()->keyBy('id');
        $items = $this->legacy->table('service_items')->orderBy('sort_order')->get();

        foreach ($items->groupBy(fn ($row) => "{$row->service_category_id}|{$row->name}") as $tiers) {
            $first = $tiers->first();
            $categoryRow = $categories[$first->service_category_id] ?? null;

            $category = $categoryRow ? ServiceCategory::firstOrCreate(
                ['legacy_id' => $categoryRow->id],
                ['name' => $categoryRow->name, 'sort_order' => $categoryRow->sort_order ?? 0],
            ) : null;

            if ($category?->wasRecentlyCreated) {
                $this->count('服務分類');
            }

            $price = (int) $tiers->min('price');
            $isAddOn = in_array($categoryRow?->name, self::ADD_ON_CATEGORIES, true)
                || $tiers->every(fn ($row) => ($row->config ?? 'standard') === 'optional');

            $service = Service::firstOrCreate(
                ['service_category_id' => $category?->getKey(), 'name' => $first->name],
                [
                    'legacy_id' => $first->id,
                    'type' => $isAddOn ? ServiceType::Optional : ServiceType::Standard,
                    'price_small' => $price,
                    'price_medium' => $price,
                    'price_large' => $price,
                    'duration_minutes' => 60,
                    'sort_order' => $first->sort_order ?? 0,
                ],
            );

            if ($service->wasRecentlyCreated) {
                $this->count('服務項目');
                $this->count('合併前的舊服務', $tiers->count());
            }

            foreach ($tiers as $tier) {
                $this->services[$tier->id] = $service;
                $this->oldPrices[$tier->id] = (int) $tier->price;
            }
        }
    }

    private function importCustomers(): void
    {
        foreach ($this->legacy->table('customers')->orderBy('id')->get() as $row) {
            if (Customer::where('legacy_id', $row->id)->exists()) {
                continue;
            }

            $name = $this->clean($row->name) ?? '未命名客戶';
            $phone = Customer::normalizePhone($row->phone ?? null) ?: null;
            $notes = $this->clean($row->remark ?? null);

            if ($phone && Customer::where('phone', $phone)->exists()) {
                $this->issues[] = "客戶「{$name}」的電話 {$phone} 與其他客戶重複，已移到備註，請確認是否為同一人。";
                $notes = trim("舊系統電話：{$phone}\n{$notes}");
                $phone = null;
            }

            if (! $phone) {
                $this->count('沒有有效電話的客戶');
            }

            $customer = new Customer([
                'legacy_id' => $row->id,
                'name' => $name,
                'phone' => $phone,
                'email' => $this->clean($row->email ?? null),
                'address' => $this->clean($row->address ?? null),
                'notes' => $notes,
            ]);
            $customer->created_at = $row->created_at ?? now();
            $customer->save();

            $this->count('客戶');
        }
    }

    private function importPets(): void
    {
        $types = $this->legacy->table('pet_types')->pluck('name', 'id');
        $petsPerOwner = $this->legacy->table('pets')->get()->countBy('customer_id');
        $owners = $this->legacy->table('customers')->get()->keyBy('id');

        foreach ($this->legacy->table('pets')->orderBy('id')->get() as $row) {
            if (Pet::where('legacy_id', $row->id)->exists()) {
                continue;
            }

            $petName = $this->clean($row->name) ?? '未命名';
            $owner = Customer::where('legacy_id', $row->customer_id)->first();

            if (! $owner) {
                $owner = Customer::firstOrCreate(['name' => '未指定飼主', 'phone' => null]);
                $this->issues[] = "寵物「{$petName}」在舊系統沒有飼主，已放到「未指定飼主」。";
            }

            if (! in_array($row->size ?? null, ['small', 'medium', 'large'], true)) {
                $this->issues[] = "寵物「{$petName}」沒有體型資料，已先設為小型。";
            }

            $type = $types[$row->pet_type_id ?? 0] ?? '狗';
            $notes = $this->clean($row->notes ?? null);
            $ownerRow = $owners[$row->customer_id] ?? null;

            // A remark on the owner only says which dog bites when there is just the one.
            $bites = str_contains((string) $notes, '咬')
                || (($petsPerOwner[$row->customer_id] ?? 0) === 1
                    && str_contains(($ownerRow->name ?? '').($ownerRow->remark ?? ''), '咬'));

            if ($bites) {
                $this->count('標記會咬人的寵物');
            }

            $pet = new Pet([
                'legacy_id' => $row->id,
                'customer_id' => $owner->getKey(),
                'name' => $petName,
                'species' => match ($type) {
                    '狗' => PetSpecies::Dog,
                    '貓' => PetSpecies::Cat,
                    default => PetSpecies::Other,
                },
                'breed' => $this->clean($row->pet_breed ?? null),
                'size' => PetSize::tryFrom((string) ($row->size ?? '')) ?? PetSize::Small,
                'gender' => in_array($row->gender ?? null, ['male', 'female'], true) ? $row->gender : 'unknown',
                'birthday' => $row->birth_date ?? null,
                'coat_color' => $this->clean($row->color ?? null),
                'weight_kg' => $row->weight ?? null,
                'chip_number' => $this->clean($row->chip_number ?? null),
                'is_neutered' => (bool) ($row->is_neutered ?? false),
                'temperament' => $bites ? PetTemperament::Bites : PetTemperament::Gentle,
                'health_notes' => $this->clean($row->medical_history ?? null),
                'grooming_preferences' => $notes,
            ]);
            $pet->created_at = $row->created_at ?? now();
            $pet->save();

            $this->count('寵物');
        }
    }

    private function importOrders(): void
    {
        $pivot = $this->legacy->table('order_item_service_items')->orderBy('id')->get()->groupBy('order_item_id');
        $rows = $this->legacy->table('order_items')->orderBy('service_date')->orderBy('id')->get()->groupBy('order_id');
        $orders = $this->legacy->table('orders')->orderBy('start_date')->orderBy('id')->get();
        $owners = $this->legacy->table('pets')->pluck('customer_id', 'id');

        $groups = [];

        foreach ($orders->filter(fn ($order) => $this->isPackageOrder($order))->groupBy('pet_id') as $petId => $petOrders) {
            foreach ($this->groupIntoPackages($petOrders) as $group) {
                $groups[] = $group + ['pet_id' => (int) $petId, 'customer_id' => $owners[$petId] ?? null];
            }
        }

        $packageByOrder = [];

        foreach ($this->clusterSharedPackages($groups) as $cluster) {
            $package = $this->importPackage($cluster, $rows, $pivot);

            foreach ($cluster as $group) {
                foreach ($group['orders'] as $order) {
                    $packageByOrder[$order->id] = $package;
                }
            }
        }

        foreach ($orders as $order) {
            $pet = Pet::where('legacy_id', $order->pet_id)->first();

            if (! $pet) {
                $this->issues[] = "訂單 #{$order->id} 找不到寵物，已略過。";

                continue;
            }

            $visits = $rows->get($order->id, collect());

            if ($visits->isEmpty() && ! isset($packageByOrder[$order->id])) {
                $this->issues[] = "訂單 #{$order->id} 沒有任何服務紀錄，已略過。";
            }

            foreach ($visits as $visit) {
                $this->importVisit($visit, $order, $pet, $packageByOrder[$order->id] ?? null, $pivot->get($visit->id, collect()));
            }
        }
    }

    /** Some package visits were entered as one-off orders, but their remark still says 包月. */
    private function isPackageOrder(object $order): bool
    {
        return $order->order_type === 'monthly' || str_contains((string) ($order->remarks ?? ''), '包月');
    }

    /** @return array{page: ?int, index: int} Paper-book page and which visit of the package this was. */
    private function parsePackageRemark(object $order): array
    {
        $remark = (string) ($order->remarks ?? '');
        $page = preg_match('/[Pp]\s*\.?\s*(\d+)/u', $remark, $match) ? (int) $match[1] : null;
        $index = preg_match('/包月\s*(\d)/u', $remark, $match)
            ? (int) $match[1]
            : max(1, self::PACKAGE_VISITS + 1 - (int) ($order->remaining_visits ?? self::PACKAGE_VISITS));

        return ['page' => $page, 'index' => $index];
    }

    /**
     * The old system made a separate order per visit, tied together only by a remark
     * such as "P72包月3" (page 72 of the paper book, third visit). This puts one dog's
     * visits back together. A page going back to a lower visit means a new package.
     *
     * @return list<array{orders: list<object>, page: ?int, first: int, last: int}>
     */
    private function groupIntoPackages(Collection $petOrders): array
    {
        $groups = [];
        $unpaged = [];
        $openByPage = [];

        foreach ($petOrders as $order) {
            ['page' => $page, 'index' => $index] = $this->parsePackageRemark($order);

            if ($page === null) {
                $unpaged[] = [$order, $index];

                continue;
            }

            $key = $openByPage[$page] ?? null;

            if ($key === null || $index < $groups[$key]['last']) {
                $groups[] = ['orders' => [], 'page' => $page, 'first' => $index, 'last' => $index];
                $key = array_key_last($groups);
                $openByPage[$page] = $key;
            }

            $groups[$key]['orders'][] = $order;
            $groups[$key]['last'] = max($groups[$key]['last'], $index);
        }

        // An order with no page is usually the visit that opened the package on the next page.
        foreach ($unpaged as [$order, $index]) {
            foreach ($groups as $key => $group) {
                if ($group['first'] > $index && $group['orders'][0]->start_date >= $order->start_date) {
                    array_unshift($groups[$key]['orders'], $order);
                    $groups[$key]['first'] = $index;

                    continue 2;
                }
            }

            $groups[] = ['orders' => [$order], 'page' => null, 'first' => $index, 'last' => $index];
        }

        return $groups;
    }

    /**
     * Several dogs of one owner often shared a page of the paper book. Their visits on
     * the same page at overlapping times become one package, with each dog counted apart.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<list<array<string, mixed>>>
     */
    private function clusterSharedPackages(array $groups): array
    {
        $clusters = [];
        $open = [];

        $span = fn (array $group) => [
            collect($group['orders'])->min('start_date'),
            collect($group['orders'])->max('start_date'),
        ];

        usort($groups, fn ($a, $b) => $span($a)[0] <=> $span($b)[0]);

        foreach ($groups as $group) {
            if ($group['page'] === null || $group['customer_id'] === null) {
                $clusters[] = [$group];

                continue;
            }

            [$start, $end] = $span($group);
            $key = "{$group['customer_id']}|{$group['page']}";
            $index = $open[$key] ?? null;

            $fits = $index !== null
                && ! collect($clusters[$index])->contains('pet_id', $group['pet_id'])
                && $start <= Carbon::parse(collect($clusters[$index])->map(fn ($member) => $span($member)[1])->max())->addDays(14)->toDateString();

            if ($fits) {
                $clusters[$index][] = $group;
            } else {
                $clusters[] = [$group];
                $open[$key] = array_key_last($clusters);
            }
        }

        return $clusters;
    }

    /** @param list<array<string, mixed>> $cluster One or more dogs' visits that make up a package. */
    private function importPackage(array $cluster, Collection $rows, Collection $pivot): ?Package
    {
        $orders = collect($cluster)->flatMap(fn ($group) => $group['orders']);
        $legacyId = (int) $orders->min('id');
        $existing = Package::where('legacy_order_id', $legacyId)->first();

        if ($existing) {
            return $existing;
        }

        $pets = Pet::whereIn('legacy_id', collect($cluster)->pluck('pet_id'))->get()->keyBy('legacy_id');

        if ($pets->isEmpty()) {
            return null;
        }

        $firstOrder = $orders->sortBy('id')->first();
        $visits = $orders->flatMap(fn ($order) => $rows->get($order->id, collect()));
        $service = $this->services[$firstOrder->service_item_id ?? 0]
            ?? $this->mainServiceFromVisits($visits, $pivot)
            ?? $this->placeholderService();
        $startsOn = Carbon::parse($orders->min('start_date'));
        $page = $cluster[0]['page'];

        // The visits themselves carry the money, so paid_at stays empty to avoid counting it twice.
        $package = Package::create([
            'customer_id' => $pets->first()->customer_id,
            'service_id' => $service->getKey(),
            'service_name' => $service->name,
            'discount_rate' => Setting::DEFAULTS['package_discount'],
            'price' => (int) $visits->sum('price'),
            'starts_on' => $startsOn,
            'expires_on' => $orders->max('end_date') ?? $startsOn->copy()->addMonthNoOverflow(),
            'cancelled_at' => ($firstOrder->status ?? null) === 'disabled' ? ($firstOrder->updated_at ?? now()) : null,
            'notes' => $page ? "舊系統紙本第 {$page} 頁" : '舊系統匯入',
            'legacy_order_id' => $legacyId,
        ]);

        foreach ($cluster as $group) {
            $pet = $pets[$group['pet_id']] ?? null;

            if (! $pet) {
                continue;
            }

            $count = collect($group['orders'])->sum(fn ($order) => $rows->get($order->id, collect())->count());
            // Counts up to the highest visit written down, even if one in between was never entered.
            $opening = max(0, $group['first'] - 1, $group['last'] - $count);

            $package->pets()->attach($pet->getKey(), [
                'unit_price' => (int) ($this->oldPrices[$group['orders'][0]->service_item_id ?? 0] ?? $pet->priceFor($service)),
                'total_visits' => max(self::PACKAGE_VISITS, $opening + $count),
                'used_adjustment' => $opening,
            ]);
        }

        $this->count('包月方案');

        if (count($cluster) > 1) {
            $this->count('多隻狗共用的包月方案');
        }

        return $package;
    }

    private function mainServiceFromVisits(Collection $visits, Collection $pivot): ?Service
    {
        foreach ($visits as $visit) {
            foreach ($pivot->get($visit->id, collect()) as $link) {
                $service = $this->services[$link->service_item_id] ?? null;

                if ($service?->type === ServiceType::Standard) {
                    return $service;
                }
            }
        }

        return null;
    }

    private function placeholderService(): Service
    {
        return Service::firstOrCreate(['name' => '舊系統服務'], [
            'is_active' => false,
            'price_small' => 0,
            'price_medium' => 0,
            'price_large' => 0,
            'duration_minutes' => 60,
        ]);
    }

    private function importVisit(object $visit, object $order, Pet $pet, ?Package $package, Collection $links): void
    {
        if (Appointment::where('legacy_order_item_id', $visit->id)->exists()) {
            return;
        }

        $startsAt = Carbon::parse($visit->service_date)->setTime(self::VISIT_HOUR, 0);
        $isFuture = $startsAt->copy()->startOfDay()->gt(today());
        $status = match (true) {
            ($order->status ?? null) === 'disabled' => AppointmentStatus::Cancelled,
            $isFuture => AppointmentStatus::Scheduled,
            default => AppointmentStatus::Completed,
        };

        $remarks = implode("\n", array_filter([
            $this->clean($visit->remarks ?? null),
            $this->clean($order->remarks ?? null),
        ]));

        $appointment = Appointment::create([
            'pet_id' => $pet->getKey(),
            'package_id' => $package?->getKey(),
            'legacy_order_item_id' => $visit->id,
            'starts_at' => $startsAt,
            'status' => $status,
            'notes' => $remarks ?: null,
            'payment_method' => preg_match('/l\s*-?\s*pay|line\s*pay|ＬＩＮＥ/iu', $remarks) ? PaymentMethod::LinePay : null,
            'paid_at' => $status === AppointmentStatus::Completed ? $startsAt : null,
        ]);

        $lines = $links
            ->filter(fn ($link) => isset($this->services[$link->service_item_id]))
            ->map(fn ($link) => [$this->services[$link->service_item_id], $this->oldPrices[$link->service_item_id]])
            ->values();

        if ($lines->isEmpty()) {
            $service = $this->services[$order->service_item_id ?? 0] ?? null;
            $lines = collect([[$service ?? $this->placeholderService(), 1]]);
            $this->count('沒有服務明細的到店紀錄');
        }

        // Events are skipped because the old amounts must be kept exactly as they were.
        $isEstimate = (int) $visit->price !== (int) $lines->sum(1);

        AppointmentItem::withoutEvents(function () use ($appointment, $lines, $visit, $isEstimate) {
            foreach ($this->splitPrice((int) $visit->price, $lines) as [$service, $price]) {
                AppointmentItem::create([
                    'appointment_id' => $appointment->getKey(),
                    'service_id' => $service->getKey(),
                    'name' => $service->name,
                    'price' => $price,
                    'price_is_estimate' => $isEstimate,
                    'duration_minutes' => $service->duration_minutes,
                ]);
            }
        });

        $appointment->refresh()->save();
        $this->count('到店紀錄');

        if ($isEstimate) {
            $this->count('總額與定價不符的到店紀錄');
        }
    }

    /**
     * The old system stored one amount per visit. Add-ons keep their old prices and the
     * first service takes the rest, since a discount or surcharge is usually on the main
     * job. If that can't work, the amount is shared by price so the total still matches.
     *
     * @param  Collection<int, array{0: Service, 1: int}>  $lines  Service and its old price.
     * @return list<array{0: Service, 1: int}>
     */
    private function splitPrice(int $amount, Collection $lines): array
    {
        $others = (int) $lines->slice(1)->sum(1);

        if ($others <= $amount) {
            return $lines
                ->map(fn (array $line, int $index) => [$line[0], $index === 0 ? $amount - $others : $line[1]])
                ->all();
        }

        $totalWeight = $lines->sum(1);
        $remaining = $amount;
        $result = [];

        foreach ($lines as $index => [$service, $weight]) {
            $share = $index === $lines->count() - 1
                ? $remaining
                : (int) floor($amount * $weight / $totalWeight);
            $remaining -= $share;
            $result[] = [$service, $share];
        }

        return $result;
    }

    private function importProductOrders(): void
    {
        $items = $this->legacy->table('product_order_items')->get()->groupBy('order_id');

        foreach ($this->legacy->table('product_orders')->orderBy('id')->get() as $row) {
            if (ProductOrder::where('legacy_id', $row->id)->exists()) {
                continue;
            }

            $status = $row->payment_status ?? 'paid';
            $paidAt = in_array($status, ['paid', 'refunded'], true)
                ? ($row->paid_at ?? $row->order_date ?? $row->created_at)
                : null;
            $lines = $items->get($row->id, collect());
            $linesTotal = (int) $lines->sum(fn ($line) => $line->price * $line->quantity);
            $total = (int) $row->total_price;
            $lineNotes = $lines->map(fn ($line) => $this->clean($line->notes ?? null))->filter()->implode('、');
            $notes = implode("\n", array_filter([$this->clean($row->remarks ?? null), $lineNotes]));

            $order = new ProductOrder([
                'legacy_id' => $row->id,
                'order_number' => $row->order_number,
                'customer_id' => Customer::where('legacy_id', $row->customer_id ?? 0)->value('id'),
                'discount' => max(0, $linesTotal - $total),
                'payment_method' => $this->paymentMethod($row->payment_method ?? null, $notes),
                'paid_at' => $paidAt,
                'refunded_at' => $status === 'refunded' ? ($row->updated_at ?? $paidAt) : null,
                'notes' => $notes ?: null,
            ]);
            $order->created_at = $row->created_at ?? now();
            $order->save();

            ProductOrderItem::withoutEvents(function () use ($order, $lines, $linesTotal, $total) {
                foreach ($lines as $line) {
                    $name = $this->clean($line->product_name) ?? '未命名商品';
                    $product = Product::firstOrCreate(['name' => $name], ['price' => (int) $line->price]);

                    if ($product->wasRecentlyCreated) {
                        $this->count('商品');
                    }

                    ProductOrderItem::create([
                        'product_order_id' => $order->getKey(),
                        'product_id' => $product->getKey(),
                        'name' => $name,
                        'price' => (int) $line->price,
                        'quantity' => (int) $line->quantity,
                    ]);
                }

                // Keeps the order's total identical when the old lines added up to less.
                if ($total > $linesTotal) {
                    ProductOrderItem::create([
                        'product_order_id' => $order->getKey(),
                        'name' => '舊系統金額差額',
                        'price' => $total - $linesTotal,
                        'quantity' => 1,
                    ]);
                }
            });

            $order->refresh()->save();
            $this->count('商品訂單');
        }
    }

    private function paymentMethod(?string $value, string $notes = ''): ?string
    {
        if (preg_match('/l\s*-?\s*pay|line\s*pay/iu', $notes)) {
            return 'line_pay';
        }

        return match ($value) {
            'cash', '現金' => 'cash',
            'credit_card', 'card', '信用卡' => 'card',
            'bank_transfer', 'transfer', '轉帳' => 'transfer',
            'line_pay', 'LINE Pay' => 'line_pay',
            null, '' => null,
            default => 'other',
        };
    }

    /** Sets next-visit dates, without flooding reminders with customers long gone. */
    private function refreshReminders(): void
    {
        $cutoff = today()->subDays(self::REMINDER_CUTOFF_DAYS);

        Pet::whereNotNull('legacy_id')->each(function (Pet $pet) use ($cutoff) {
            $pet->refreshNextVisit();

            if ($pet->next_visit_due_on?->lt($cutoff)) {
                $pet->updateQuietly(['next_visit_due_on' => null]);
            }
        });
    }

    /**
     * Compares the money in both systems. Cancelled orders and future visits are listed
     * separately because they intentionally don't count as revenue here.
     *
     * @return array<string, int>
     */
    private function reconcile(): array
    {
        $today = today()->toDateString();
        $visits = $this->legacy->table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id');

        $all = (int) (clone $visits)->sum('order_items.price');
        $cancelled = (int) (clone $visits)->where('orders.status', 'disabled')->sum('order_items.price');
        $future = (int) (clone $visits)
            ->where(fn ($query) => $query->whereNull('orders.status')->orWhere('orders.status', '!=', 'disabled'))
            ->where('order_items.service_date', '>', $today)
            ->sum('order_items.price');

        $products = $this->legacy->table('product_orders');

        return [
            '舊系統美容總額' => $all,
            '其中已取消訂單' => $cancelled,
            '其中未來日期' => $future,
            '應匯入美容營收' => $all - $cancelled - $future,
            '實際匯入美容營收' => (int) Appointment::whereNotNull('legacy_order_item_id')->whereNotNull('paid_at')->sum('total'),
            '應匯入商品營收' => (int) (clone $products)->whereIn('payment_status', ['paid', 'refunded'])->sum('total_price'),
            '實際匯入商品營收' => (int) ProductOrder::whereNotNull('legacy_id')->whereNotNull('paid_at')->sum('total'),
        ];
    }
}
