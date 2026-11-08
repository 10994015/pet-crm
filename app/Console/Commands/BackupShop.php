<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

class BackupShop extends Command
{
    protected $signature = 'babygo:backup';

    protected $description = 'Back up the database and uploaded photos, and remove old backups';

    public function handle(Backup $backup): int
    {
        try {
            $file = $backup->create();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('備份失敗：'.$exception->getMessage());

            return self::FAILURE;
        }

        $removed = $backup->prune((int) config('backup.keep_days'));

        $this->info('備份完成：'.$file->getPathname().'（'.Number::fileSize($file->getSize(), 1).'）');

        if ($removed > 0) {
            $this->line("已刪除 {$removed} 個過期的備份。");
        }

        return self::SUCCESS;
    }
}
