<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Seo\Redirects\RedirectCsv;

/**
 * Bulk redirect management: import a CSV exported from a legacy site or another
 * SEO plugin, or export the current rule set for review and version control.
 */
final class SeoRedirectsCommand extends Command
{
    protected $signature = 'seo:redirects
        {action : import or export}
        {file : Path to the CSV file to read or write}';

    protected $description = 'Import or export redirect rules as CSV.';

    public function handle(RedirectCsv $csv): int
    {
        $action = (string) $this->argument('action');
        $file = (string) $this->argument('file');

        return match ($action) {
            'import' => $this->import($csv, $file),
            'export' => $this->export($csv, $file),
            default => $this->bail("Unknown action \"{$action}\". Use import or export."),
        };
    }

    private function import(RedirectCsv $csv, string $file): int
    {
        if (! is_readable($file)) {
            return $this->bail("Cannot read {$file}.");
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return $this->bail("Cannot open {$file}.");
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_map(static fn (mixed $cell): string => is_string($cell) ? $cell : '', $row);
        }
        fclose($handle);

        $result = $csv->import($rows);

        $this->info("Imported {$result['imported']}, updated {$result['updated']}, skipped {$result['skipped']}.");

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }

    private function export(RedirectCsv $csv, string $file): int
    {
        $handle = fopen($file, 'wb');

        if ($handle === false) {
            return $this->bail("Cannot write {$file}.");
        }

        $rows = $csv->export();

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fclose($handle);

        $this->info('Exported '.(count($rows) - 1)." rule(s) to {$file}.");

        return self::SUCCESS;
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
