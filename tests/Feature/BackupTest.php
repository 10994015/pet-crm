<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ShopSettings;
use App\Models\Customer;
use App\Models\User;
use App\Services\Backup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;
use ZipArchive;

// Not RefreshDatabase: SQLite can't take a snapshot from inside the transaction that wraps each test.
class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'babygo-backup-test-'.uniqid();
        config(['backup.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::delete(storage_path('app/public/backup-test-photo.txt'));

        parent::tearDown();
    }

    private function backup(): Backup
    {
        return app(Backup::class);
    }

    private function fakeOldBackup(string $name, int $daysOld): string
    {
        File::ensureDirectoryExists($this->directory);
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        File::put($path, 'x');
        touch($path, now()->subDays($daysOld)->getTimestamp());

        return $path;
    }

    public function test_a_backup_can_actually_be_restored_with_data_and_photos(): void
    {
        Customer::create(['name' => '王小美', 'phone' => '0912345678']);
        File::put(storage_path('app/public/backup-test-photo.txt'), 'photo');

        $file = $this->backup()->create();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $this->assertSame('photo', $zip->getFromName('uploads/backup-test-photo.txt'));
        $this->assertFalse($zip->getFromName('uploads/.gitignore'));

        $zip->extractTo($this->directory.'/restored', 'database.sqlite');
        $zip->close();

        $restored = new PDO('sqlite:'.$this->directory.'/restored/database.sqlite');
        $this->assertSame('王小美', $restored->query('select name from customers')->fetchColumn());
        unset($restored);
    }

    public function test_nothing_half_finished_is_left_behind(): void
    {
        $this->backup()->create();

        $this->assertCount(1, File::allFiles($this->directory, hidden: true));
    }

    public function test_old_backups_are_removed_but_the_newest_and_other_files_are_kept(): void
    {
        $older = $this->fakeOldBackup('babygo-20260101-030000.zip', 30);
        $newest = $this->fakeOldBackup('babygo-20260102-030000.zip', 29);
        $recent = $this->fakeOldBackup('babygo-20260103-030000.zip', 5);
        $unrelated = $this->fakeOldBackup('my-notes.zip', 30);
        $leftover = $this->fakeOldBackup('.dump-abc', 3);

        $this->assertSame(2, $this->backup()->prune(14));

        $this->assertFileDoesNotExist($older);
        $this->assertFileDoesNotExist($newest, 'not the newest, so it goes');
        $this->assertFileExists($recent);
        $this->assertFileExists($unrelated);
        $this->assertFileDoesNotExist($leftover);
    }

    public function test_the_only_backup_survives_however_old_it_is(): void
    {
        $only = $this->fakeOldBackup('babygo-20200101-030000.zip', 2000);

        $this->assertSame(0, $this->backup()->prune(14));
        $this->assertFileExists($only);
    }

    public function test_the_command_runs_and_is_scheduled_every_night(): void
    {
        $this->artisan('babygo:backup')->expectsOutputToContain('備份完成')->assertSuccessful();
        $this->assertNotNull($this->backup()->latest());

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'babygo:backup'));
        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
    }

    public function test_the_command_reports_failure_instead_of_pretending(): void
    {
        File::put($this->directory, 'a file where the folder should be');

        try {
            $this->artisan('babygo:backup')->assertFailed();
        } finally {
            File::delete($this->directory);
        }
    }

    public function test_only_an_active_owner_can_download_a_backup(): void
    {
        $this->backup()->create();

        $this->get('/admin/backups/latest')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
        $this->get('/admin/backups/latest')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner, 'is_active' => false]));
        $this->get('/admin/backups/latest')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]));
        $this->get('/admin/backups/latest')->assertOk()->assertDownload();
    }

    public function test_a_website_member_cannot_download_a_backup(): void
    {
        $this->backup()->create();
        $member = Customer::create(['name' => '會員', 'phone' => '0912000000']);
        $member->activate('secret123');

        $this->actingAs($member, 'member')->get('/admin/backups/latest')->assertForbidden();
    }

    public function test_the_owner_backs_up_from_the_settings_page_and_sees_a_stale_warning(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]));

        Livewire::test(ShopSettings::class)
            ->assertSee('還沒有備份')
            ->assertActionHidden('downloadBackup')
            ->callAction('backupNow')
            ->assertNotified('備份完成');

        $this->assertNotNull($this->backup()->latest());
        Livewire::test(ShopSettings::class)->assertActionVisible('downloadBackup')->assertDontSee('已超過兩天');

        $this->travel(3)->days();
        Livewire::test(ShopSettings::class)->assertSee('已超過兩天');
    }
}
