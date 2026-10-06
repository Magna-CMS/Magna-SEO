<?php

declare(strict_types=1);

namespace Magna\Seo\Listeners;

use Illuminate\Support\Facades\Log;
use Magna\Content\Entry;
use Magna\Seo\Integrations\IndexNowQueue;
use Magna\Seo\Jobs\FlushIndexNowQueueJob;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Url\EntryUrlResolver;
use Throwable;

/**
 * Tells IndexNow that an entry's URL changed, when the site has opted in.
 *
 * Deletions and unpublishes are submitted too: IndexNow's contract is "this URL
 * changed, come and look", and a URL that now 404s is exactly what an engine
 * should be told to re-fetch and drop.
 *
 * Nothing here is allowed to break a content save. A failure to work out a URL,
 * or to reach the queue, is logged and dropped.
 */
final class PingIndexNow
{
    public function __construct(
        private readonly IndexNowQueue $queue,
        private readonly EntryUrlResolver $urls,
        private readonly int $delaySeconds = 60,
    ) {}

    public function handle(object $event): void
    {
        try {
            if (! SeoSettings::get()->indexnow_auto_submit) {
                return;
            }

            $entry = $event->entry ?? null;

            if (! $entry instanceof Entry) {
                return;
            }

            $url = $this->urlFor($entry);

            if ($url === '') {
                return;
            }

            if ($this->queue->push($url)) {
                FlushIndexNowQueueJob::dispatch()->delay(now()->addSeconds($this->delaySeconds));
            }
        } catch (Throwable $e) {
            Log::warning('IndexNow ping skipped.', ['exception' => $e->getMessage()]);
        }
    }

    private function urlFor(Entry $entry): string
    {
        $handle = $entry->getHandle();

        if ($handle === null) {
            return '';
        }

        // The resolver reads a payload array, which is what the delivery API hands
        // it; an entry's attributes are the same shape.
        return $this->urls->forPayload($handle, $entry->attributesToArray());
    }
}
