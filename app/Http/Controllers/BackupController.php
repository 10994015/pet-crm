<?php

namespace App\Http\Controllers;

use App\Services\Backup;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    /** Streamed by a plain route: a backup can be far bigger than a Livewire response can carry. */
    public function latest(Backup $backup): BinaryFileResponse
    {
        // Staff accounts only, named outright: website members sign in on a guard of their own.
        $user = auth('web')->user();

        abort_unless($user?->isOwner() && $user->is_active, 403);

        $file = $backup->latest();

        abort_unless($file, 404);

        return response()->download($file->getPathname());
    }
}
