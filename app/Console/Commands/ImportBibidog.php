<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\BibidogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportBibidog extends Command
{
    protected $signature = 'bibidog:import
        {--dry-run : Run everything and report the result, then undo it}
        {--allow-existing : Import even though this system already has its own customers}';

    protected $description = 'Copy customers, pets, services, orders and sales from the old bibidog database';

    public function handle(): int
    {
        $existing = Customer::whereNull('legacy_id')->count();

        if ($existing > 0 && ! $this->option('allow-existing')) {
            $this->error("目前系統已有 {$existing} 位不是匯入的客戶（可能是示範資料）。");
            $this->line('請先執行 php artisan migrate:fresh --seed 清空，或加上 --allow-existing 合併匯入。');

            return self::FAILURE;
        }

        $report = (new BibidogImporter(DB::connection('bibidog')))->run((bool) $this->option('dry-run'));

        $this->table(['項目', '筆數'], collect($report['counts'])->map(fn ($count, $name) => [$name, $count])->values());
        $this->table(['金額核對', '金額'], collect($report['reconciliation'])->map(fn ($amount, $name) => [$name, number_format($amount)])->values());

        $money = $report['reconciliation'];
        $matches = $money['應匯入美容營收'] === $money['實際匯入美容營收']
            && $money['應匯入商品營收'] === $money['實際匯入商品營收'];

        $matches ? $this->info('金額核對相符。') : $this->error('金額核對不相符，請先檢查再正式使用。');

        foreach ($report['issues'] as $issue) {
            $this->warn($issue);
        }

        if ($this->option('dry-run')) {
            $this->comment('這是試跑，資料沒有寫入。');
        }

        return $matches ? self::SUCCESS : self::FAILURE;
    }
}
