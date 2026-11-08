<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** The way back in when the owner forgets their password and the site can't send email. */
class ResetStaffPassword extends Command
{
    protected $signature = 'babygo:reset-password {login : Username or email of the staff account}';

    protected $description = 'Give a staff account a new random password';

    public function handle(): int
    {
        $login = (string) $this->argument('login');
        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if (! $user) {
            $this->error("找不到帳號：{$login}");

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        // Commands aren't logged automatically, and this one is worth knowing about.
        ActivityLog::record('password_reset', 'User', $user->getKey(), $user->name, actor: '系統指令');

        $this->info("{$user->name}（{$user->username}）的新密碼：{$password}");
        $this->line('這組密碼只會顯示這一次，登入後請到個人資料更改。');

        return self::SUCCESS;
    }
}
