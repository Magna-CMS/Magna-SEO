<?php

declare(strict_types=1);

namespace Magna\Seo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Magna\Seo\Scan\SiteScanner;

/**
 * Runs the site scan off the request cycle.
 *
 * Unique for its duration: a scheduled run and an editor pressing "Run scan"
 * should not walk the whole site twice at once, and the second result would
 * simply overwrite the first.
 */
final class RunSiteScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Give up rather than pile up: the next scheduled scan will try again. */
    public int $tries = 1;

    public int $timeout = 900;

    public function uniqueId(): string
    {
        return 'seo-site-scan';
    }

    public function handle(SiteScanner $scanner): void
    {
        $scanner->scan();
    }
}
