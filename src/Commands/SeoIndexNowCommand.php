<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Seo\Integrations\IndexNow;

/**
 * Submits every indexable URL to IndexNow for instant indexing. Generates and
 * persists a key on first run (then served at /{key}.txt).
 */
final class SeoIndexNowCommand extends Command
{
    protected $signature = 'seo:indexnow';

    protected $description = 'Submit all indexable URLs to IndexNow.';

    public function handle(IndexNow $indexNow): int
    {
        $count = $indexNow->submitAll();

        if ($count === 0) {
            $this->warn('Nothing was submitted (no indexable URLs, or the submission failed).');

            return self::SUCCESS;
        }

        $this->info("Submitted {$count} URLs to IndexNow.");

        return self::SUCCESS;
    }
}
