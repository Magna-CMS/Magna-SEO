<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Seo\Scorecard\Scorecard;

/**
 * Runs the "SEO-friendly by construction" scorecard and exits non-zero if any
 * invariant fails — designed to gate CI so a regression cannot ship silently.
 */
final class SeoScorecardCommand extends Command
{
    protected $signature = 'seo:scorecard';

    protected $description = 'Check the technical-SEO invariants that must hold for every Magna site.';

    public function handle(Scorecard $scorecard): int
    {
        $report = $scorecard->run();

        foreach ($report->results as $result) {
            $tag = $result->passed ? '<info>PASS</info>' : '<error>FAIL</error>';
            $this->line("  [{$tag}] {$result->label} — {$result->detail}");
        }

        if (! $report->passed) {
            $this->error('Scorecard failed.');

            return self::FAILURE;
        }

        $this->info('Scorecard passed.');

        return self::SUCCESS;
    }
}
