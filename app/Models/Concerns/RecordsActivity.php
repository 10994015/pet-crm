<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Leaves a line in the activity log when a record is added, changed or removed. Only sees
 * changes made through the model; artisan commands such as imports and seeders are skipped,
 * or one import would bury everything else.
 */
trait RecordsActivity
{
    public static function bootRecordsActivity(): void
    {
        static::created(fn (self $model) => $model->logActivity('created', collect($model->getAttributes())
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => [null, $value])
            ->all()));

        static::updated(fn (self $model) => $model->logActivity('updated', collect($model->getChanges())
            ->map(fn ($value, string $field) => [$model->getRawOriginal($field), $value])
            ->all()));

        static::deleted(fn (self $model) => $model->logActivity('deleted', collect($model->getAttributes())
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => [$value, null])
            ->all()));
    }

    /** What to call this record in the log. */
    public function activityLabel(): string
    {
        return (string) ($this->getAttribute('name') ?? $this->getAttribute('order_number') ?? $this->getKey());
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private function logActivity(string $action, array $changes): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        // A save that only touched bookkeeping columns isn't worth a line.
        if ($action === 'updated' && array_diff(array_keys($changes), ['updated_at', 'remember_token', 'sort_order']) === []) {
            return;
        }

        ActivityLog::record($action, class_basename($this), $this->getKey(), $this->activityLabel(), $changes);
    }
}
