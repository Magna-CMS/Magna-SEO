<?php

declare(strict_types=1);

namespace Magna\Seo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Magna\Seo\Integrations\IndexNow;
use Magna\Seo\Integrations\IndexNowQueue;

/**
 * Submits the buffered URLs to IndexNow as one batch.
 *
 * A failure here must never surface to the editor who triggered it: the content
 * is already saved, and a search engine being unreachable is not their problem.
 * IndexNow itself logs and swallows transport failures, so this job just drains
 * the buffer and hands the batch over.
 */
final class FlushIndexNowQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function handle(IndexNowQueue $queue, IndexNow $indexNow): void
    {
        $urls = $queue->drain();

        if ($urls === []) {
            return;
        }

        $indexNow->submit($urls);
    }
}
