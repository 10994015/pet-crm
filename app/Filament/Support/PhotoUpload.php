<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;

class PhotoUpload
{
    /** Phone photos are shrunk in the browser before upload, so they stay quick on shop wifi. */
    public static function make(string $name, string $directory): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('public')
            ->directory($directory)
            ->visibility('public')
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('1600')
            ->automaticallyResizeImagesToHeight('1600')
            ->maxSize(20480);
    }
}
