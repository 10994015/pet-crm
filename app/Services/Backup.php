<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/** Zips the database and uploaded photos into one file, keeping the last couple of weeks of them. */
class Backup
{
    private const NAME_PATTERN = '/^babygo-\d{8}-\d{6}\.zip$/';

    public function create(): SplFileInfo
    {
        $directory = (string) config('backup.path');
        File::ensureDirectoryExists($directory, 0750);

        $dump = $directory.DIRECTORY_SEPARATOR.'.dump-'.Str::random(12);
        $target = $directory.DIRECTORY_SEPARATOR.'babygo-'.now()->format('Ymd-His').'.zip';
        $partial = "{$target}.partial";

        try {
            $name = $this->dumpDatabase($dump);
            $this->zip($partial, $dump, $name);

            // Only a finished file gets the real name, so a crash can't leave a broken "latest".
            File::move($partial, $target);
        } finally {
            File::delete([$dump, $partial]);
        }

        return new SplFileInfo($target);
    }

    public function latest(): ?SplFileInfo
    {
        return $this->all()->first();
    }

    /** Deletes backups older than the given number of days, always keeping the newest. */
    public function prune(int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays);
        $old = $this->all()->skip(1)->filter(fn (SplFileInfo $file) => Carbon::createFromTimestamp($file->getMTime())->lt($cutoff));

        File::delete($old->map(fn (SplFileInfo $file) => $file->getPathname())->all());

        // What a killed run left behind.
        foreach (File::glob(config('backup.path').'/{.dump-*,*.partial}', GLOB_BRACE) ?: [] as $leftover) {
            if (File::lastModified($leftover) < now()->subDay()->getTimestamp()) {
                File::delete($leftover);
            }
        }

        return $old->count();
    }

    /** @return Collection<int, SplFileInfo> newest first */
    private function all()
    {
        if (! File::isDirectory(config('backup.path'))) {
            return collect();
        }

        return collect(File::files(config('backup.path')))
            ->filter(fn (SplFileInfo $file) => preg_match(self::NAME_PATTERN, $file->getFilename()) === 1)
            ->sortByDesc(fn (SplFileInfo $file) => $file->getFilename())
            ->values();
    }

    /** @return string the file's name inside the backup */
    private function dumpDatabase(string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => $this->dumpSqlite($to),
            'mysql', 'mariadb' => $this->dumpMysql($to),
            default => throw new RuntimeException('這個資料庫類型還不支援備份：'.DB::connection()->getDriverName()),
        };
    }

    private function dumpSqlite(string $to): string
    {
        // Unlike copying the file, this gives a consistent snapshot while the shop keeps working.
        DB::statement('VACUUM INTO ?', [$to]);

        $check = (new PDO("sqlite:{$to}"))->query('PRAGMA integrity_check')->fetchColumn();

        if ($check !== 'ok') {
            throw new RuntimeException("資料庫備份檢查失敗：{$check}");
        }

        return 'database.sqlite';
    }

    private function dumpMysql(string $to): string
    {
        $connection = DB::connection();

        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => (string) $connection->getConfig('password')])
            ->run([
                config('backup.mysqldump'),
                '--host='.$connection->getConfig('host'),
                '--port='.$connection->getConfig('port'),
                '--user='.$connection->getConfig('username'),
                '--single-transaction',
                '--default-character-set=utf8mb4',
                '--result-file='.$to,
                $connection->getDatabaseName(),
            ]);

        // mysqldump marks the end of a complete dump, so a cut-off file doesn't pass as a backup.
        if ($result->failed() || ! File::exists($to) || ! str_contains((string) File::get($to), '-- Dump completed')) {
            throw new RuntimeException('mysqldump 失敗：'.trim($result->errorOutput()));
        }

        return 'database.sql';
    }

    private function zip(string $path, string $dump, string $dumpName): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("無法建立備份檔：{$path}");
        }

        $zip->addFile($dump, $dumpName);

        $uploads = storage_path('app/public');

        if (File::isDirectory($uploads)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                    $zip->addFile($file->getPathname(), "uploads/{$relative}");
                }
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('備份檔寫入失敗，請確認磁碟空間。');
        }
    }
}
