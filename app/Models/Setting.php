<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Shop-wide values the owner edits on the settings page. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use RecordsActivity;

    public const DEFAULTS = [
        'package_discount' => 9,      // 折: 9 = 九折, 8.5 = 85 折, 10 = 不打折
        'package_visits' => 4,
        'package_valid_months' => 1,
        'package_expiry_notice_days' => 7,
        'vip_spending' => 10000,
        'booking_enabled' => 1,
        'booking_open_hour' => 10,     // first start time
        'booking_close_hour' => 19,    // the grooming must be finished by then
        'booking_slot_minutes' => 30,
        'booking_days_ahead' => 30,
        'booking_lead_hours' => 3,     // how soon before the visit online booking closes
        'member_cancel_hours' => 24,   // members may cancel on the website up to this long before
        'booking_max_no_shows' => 2,   // no-shows in a row before someone has to call; 0 = never
        'booking_max_pending' => 20,   // unconfirmed bookings before new phones have to call; 0 = no limit
    ];

    /** Words rather than numbers, shown on the website. */
    public const TEXT_DEFAULTS = [
        'shop_phone' => '',
        'shop_address' => '',
        'shop_line' => '',
        'booking_closed_days' => '',   // weekdays, 0 = Sunday, comma separated
        'booking_notice' => '',
    ];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function value(string $key): float
    {
        return (float) (self::find($key)?->value ?? self::DEFAULTS[$key]);
    }

    public static function text(string $key): string
    {
        return (string) (self::find($key)?->value ?? self::TEXT_DEFAULTS[$key]);
    }

    /** @return array<string, float> */
    public static function current(): array
    {
        return array_map('floatval', array_merge(
            self::DEFAULTS,
            array_intersect_key(self::query()->pluck('value', 'key')->all(), self::DEFAULTS),
        ));
    }

    /** @return array<string, string> */
    public static function texts(): array
    {
        return array_map('strval', array_merge(
            self::TEXT_DEFAULTS,
            array_intersect_key(self::query()->pluck('value', 'key')->all(), self::TEXT_DEFAULTS),
        ));
    }

    /** Weekdays the shop is closed, 0 = Sunday. @return list<int> */
    public static function closedWeekdays(): array
    {
        return array_values(array_map('intval', array_filter(
            explode(',', self::text('booking_closed_days')),
            fn (string $day) => $day !== '',
        )));
    }

    /** @param  array<string, mixed>  $values */
    public static function store(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS + self::TEXT_DEFAULTS) as $key => $value) {
            self::updateOrCreate(['key' => $key], ['value' => is_array($value) ? implode(',', $value) : (string) $value]);
        }
    }
}
