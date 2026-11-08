<?php

return [
    // Outside public/, so nobody can download it from the website. Point this at a mounted
    // cloud or network folder to get copies off the server automatically.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Only used with MySQL or MariaDB.
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
];
