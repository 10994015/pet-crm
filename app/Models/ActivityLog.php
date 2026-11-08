<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Throwable;

/** Who changed what in the shop's records. Only ever added to, and cleared out after a year. */
#[Fillable(['user_id', 'actor', 'action', 'subject_type', 'subject_id', 'subject_label', 'details', 'ip_address'])]
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const KEEP_DAYS = 365;

    public const ACTIONS = ['created' => '新增', 'updated' => '修改', 'deleted' => '刪除', 'password_reset' => '重設密碼'];

    public const SUBJECTS = [
        'Appointment' => '預約',
        'Customer' => '客戶',
        'Package' => '包月方案',
        'Pet' => '寵物',
        'Product' => '商品',
        'ProductOrder' => '商品訂單',
        'Service' => '服務',
        'ServiceCategory' => '服務分類',
        'Setting' => '系統設定',
        'TimeOff' => '店休與請假',
        'User' => '員工帳號',
    ];

    /** Never kept, only the fact that they changed. */
    private const SECRET = ['password', 'activation_code'];

    /** Bookkeeping nobody needs to read about. */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'remember_token', 'sort_order'];

    private const FIELDS = [
        'name' => '名稱', 'phone' => '電話', 'backup_phone' => '備用電話', 'email' => '電子郵件', 'line_id' => 'LINE ID',
        'address' => '地址', 'notes' => '備註', 'password' => '密碼', 'activation_code' => '開通碼', 'status' => '狀態',
        'starts_at' => '時間', 'ends_at' => '結束時間', 'groomer_id' => '美容師', 'pet_id' => '寵物', 'customer_id' => '客戶',
        'total' => '金額', 'subtotal' => '小計', 'discount' => '折扣', 'price' => '價格', 'payment_method' => '付款方式',
        'paid_at' => '付款時間', 'refunded_at' => '退款時間', 'confirmed_at' => '確認時間', 'is_active' => '啟用',
        'role' => '權限', 'username' => '帳號', 'is_groomer' => '美容師身分', 'starts_on' => '開始日期', 'ends_on' => '結束日期',
        'reason' => '原因', 'expires_on' => '到期日', 'cancelled_at' => '終止時間', 'value' => '設定值', 'stock' => '庫存',
        'online_booking_blocked' => '封鎖線上預約', 'grooming_notes' => '美容紀錄', 'weight_kg' => '體重',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [before, after] */
    public static function record(string $action, string $subjectType, mixed $subjectId, ?string $subjectLabel, array $changes = [], ?string $actor = null): void
    {
        try {
            $staff = auth('web')->user();

            self::create([
                'user_id' => $staff?->getKey(),
                'actor' => $actor ?? self::currentActor(),
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_label' => $subjectLabel === null ? null : Str::limit($subjectLabel, 180),
                'details' => self::clean($changes) ?: null,
                // Staff only: the privacy notice promises customers' addresses aren't kept.
                'ip_address' => $staff ? request()->ip() : null,
            ]);
        } catch (Throwable $exception) {
            // A missing log line must never stop a booking or a sale from going through.
            report($exception);
        }
    }

    /** @return list<string> "field: before → after", for reading */
    public function lines(): array
    {
        return collect($this->details ?? [])
            ->map(function (array $values, string $field) {
                $label = self::FIELDS[$field] ?? $field;

                if (in_array($field, self::SECRET, true)) {
                    return "{$label}：已變更";
                }

                [$before, $after] = [self::readable($field, $values[0] ?? null), self::readable($field, $values[1] ?? null)];

                return match ($this->action) {
                    'created' => "{$label}：{$after}",
                    'deleted' => "{$label}：{$before}",
                    default => "{$label}：{$before} → {$after}",
                };
            })
            ->values()
            ->all();
    }

    private static function currentActor(): string
    {
        if ($staff = auth('web')->user()) {
            return $staff->name;
        }

        if ($member = auth('member')->user()) {
            return "會員 {$member->name}";
        }

        return app()->runningInConsole() && ! app()->runningUnitTests() ? '系統指令' : '網站訪客';
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private static function clean(array $changes): array
    {
        return collect($changes)
            ->except(self::IGNORED)
            ->map(fn (array $values, string $field) => in_array($field, self::SECRET, true)
                ? [null, null]
                : array_map(fn ($value) => is_string($value) ? Str::limit($value, 300) : $value, $values))
            ->all();
    }

    private static function readable(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '（空白）';
        }

        return match ($field) {
            'status' => AppointmentStatus::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            'payment_method' => PaymentMethod::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
