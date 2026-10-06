<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Seo\Jobs\RunSiteScanJob;
use Magna\Seo\Scan\SiteScanner;

/**
 * Runs the static site scan and reports a one-line summary. The full findings are
 * persisted for the SEO Health dashboard.
 */
final class SeoScanCommand extends Command
{
    protected $signature = 'seo:scan
        {--strict : Exit with a non-zero status when any issue is found}
        {--queue : Dispatch the scan to the queue instead of running it inline}';

    protected $description = 'Run the static SEO site scan over all registered content sources.';

    public function handle(SiteScanner $scanner): int
    {
        if ($this->option('queue')) {
            RunSiteScanJob::dispatch();

            $this->info('Site scan queued.');

            return self::SUCCESS;
        }

        $scan = $scanner->scan();

        $this->info("Scanned {$scan->url_count} URLs; found {$scan->issue_count} issues (scan #{$scan->id}).");

        // --strict turns the scan into a CI gate: a clean site passes, any issue
        // fails the build.
        if ($this->option('strict') && $scan->issue_count > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
