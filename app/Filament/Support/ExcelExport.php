<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelExport
{
    /**
     * A table button that downloads what the table currently shows, with its search,
     * filters and tab applied. Owner only, because exports carry customer details.
     *
     * @param  Closure(Model): array<string, mixed>  $row  Column heading => value for one record.
     */
    public static function action(string $title, Closure $row): Action
    {
        return Action::make('exportExcel')
            ->label('匯出 Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn () => auth()->user()->isOwner())
            ->action(function ($livewire) use ($title, $row) {
                $rows = $livewire->getFilteredSortedTableQuery()->get()->map($row);

                return self::download($title, [$title => $rows]);
            });
    }

    /** @param  array<string, Collection<int, array<string, mixed>>>  $sheets  Sheet name => rows. */
    public static function download(string $filename, array $sheets): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $isFirst = true;

        foreach ($sheets as $name => $rows) {
            if (! $isFirst) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName(mb_substr($name, 0, 31));
            $isFirst = false;

            if ($rows->isEmpty()) {
                continue;
            }

            $writer->addRow(Row::fromValues(array_keys($rows->first())));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_values($row)));
            }
        }

        $writer->close();

        return response()->download($path, $filename.'-'.today()->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }
}
