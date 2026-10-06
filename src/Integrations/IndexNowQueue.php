<?php

declare(strict_types=1);

namespace Magna\Seo\Integrations;

use Illuminate\Support\Facades\Cache;

/**
 * A short-lived buffer of URLs waiting to be submitted to IndexNow.
 *
 * Submitting on every save would be both wasteful and rude: editors save
 * repeatedly while working, and a bulk publish would fire hundreds of requests
 * for what is one logical change. URLs accumulate here instead and are flushed
 * as a single batch shortly afterwards, deduplicated — which is exactly the
 * shape the IndexNow API wants anyway.
 */
final class IndexNowQueue
{
    private const KEY = 'seo:indexnow:pending';

    private const SCHEDULED_KEY = 'seo:indexnow:scheduled';

    public function __construct(private readonly int $bufferSeconds = 3600) {}

    /**
     * Buffer a URL. Returns true when this call is the one that should schedule
     * the flush, so exactly one job is dispatched per batch window.
     */
    public function push(string $url): bool
    {
        if (trim($url) === '') {
            return false;
        }

        $pending = $this->pending();
        $pending[$url] = true;

        Cache::put(self::KEY, array_keys($pending), $this->bufferSeconds);

        return Cache::add(self::SCHEDULED_KEY, true, $this->bufferSeconds);
    }

    /**
     * Take everything buffered and clear the buffer, so a flush cannot submit the
     * same URL twice.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $urls = array_keys($this->pending());

        Cache::forget(self::KEY);
        Cache::forget(self::SCHEDULED_KEY);

        return $urls;
    }

    /**
     * @return array<string, true>
     */
    private function pending(): array
    {
        $cached = Cache::get(self::KEY);

        if (! is_array($cached)) {
            return [];
        }

        $pending = [];
        foreach ($cached as $url) {
            if (is_string($url) && $url !== '') {
                $pending[$url] = true;
            }
        }

        return $pending;
    }
}
