<?php

declare(strict_types=1);

namespace Magna\Seo\Listeners;

use Magna\Seo\Llms\LlmsTxtGenerator;
use Magna\Seo\Sitemap\SitemapGenerator;

/**
 * Drops the cached sitemaps and llms.txt whenever content changes, so a newly
 * published page is discoverable immediately instead of after the cache TTL
 * expires.
 *
 * Purging is a handful of cache deletes, cheap enough to do inline on a content
 * save; the expensive rebuild happens lazily on the next crawler request rather
 * than in the editor's response.
 */
final class PurgeGeneratedFiles
{
    public function __construct(
        private readonly SitemapGenerator $sitemaps,
        private readonly LlmsTxtGenerator $llms,
    ) {}

    public function handle(object $event): void
    {
        $this->purge();
    }

    public function purge(): void
    {
        $this->sitemaps->purge();
        $this->llms->purge();
    }
}
