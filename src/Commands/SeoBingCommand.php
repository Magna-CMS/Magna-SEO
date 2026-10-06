<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Integrations\BingWebmaster;
use Magna\Seo\Registry\SeoSourceRegistry;
use Throwable;

/**
 * Submits indexable URLs to Bing Webmaster Tools using the configured API key.
 * IndexNow covers Bing too and needs no account, so this is for sites that
 * already have a Webmaster property and want the submissions to appear in its
 * own reporting.
 */
final class SeoBingCommand extends Command
{
    protected $signature = 'seo:bing';

    protected $description = 'Submit indexable URLs to Bing Webmaster Tools.';

    public function handle(BingWebmaster $bing, SeoSourceRegistry $registry): int
    {
        if (! $bing->isConfigured()) {
            $this->warn('No Bing Webmaster API key is configured; nothing to do.');

            return self::SUCCESS;
        }

        $urls = $this->collect($registry);

        if ($urls === []) {
            $this->warn('No indexable URLs to submit.');

            return self::SUCCESS;
        }

        if (! $bing->submit($urls)) {
            $this->error('Bing rejected the submission; see the log for details.');

            return self::FAILURE;
        }

        $this->info('Submitted '.count($urls).' URL(s) to Bing Webmaster Tools.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collect(SeoSourceRegistry $registry): array
    {
        $urls = [];

        foreach ($registry->all() as $handle => $source) {
            try {
                $source->chunk(function (array $batch) use (&$urls): void {
                    foreach ($batch as $subject) {
                        if ($subject->indexable && $subject->url !== '') {
                            $urls[] = $subject->url;
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::warning('Bing submission: a source failed and was skipped.', [
                    'source' => $handle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($urls));
    }
}
