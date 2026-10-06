<?php

declare(strict_types=1);

namespace Magna\Seo\Sitemap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\HreflangSet;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Builds the sitemap index and per-source child sitemaps from the registered
 * content sources.
 *
 * Three properties matter here and shape the design:
 *
 * - Every source streams through chunk(), so a large site never loads all its
 *   content at once.
 * - Child sitemaps are paginated well below the 50 000-URL protocol maximum:
 *   smaller files reprocess faster after a change and isolate a failure to one
 *   page instead of the whole source.
 * - A URL an editor marked noindex must never appear here. Overrides are
 *   bulk-loaded once per chunk, so the exclusion costs one extra query per
 *   batch rather than one per URL.
 *
 * A source is built in a single streaming pass that writes every page of that
 * source to the cache at once, so serving page 7 never re-walks the content.
 */
final class SitemapGenerator
{
    private const URLSET_NS = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
        .'xmlns:xhtml="http://www.w3.org/1999/xhtml" '
        .'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';

    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * The sitemap index, listing every page of every registered source.
     */
    public function index(): string
    {
        $cached = Cache::get('seo:sitemap:index');
        if (is_string($cached)) {
            return $cached;
        }

        $body = '';
        $complete = true;

        foreach ($this->registry->all() as $handle => $source) {
            $pages = $this->pageCount($handle, $source);

            if ($pages === null) {
                $complete = false;

                continue;
            }

            for ($page = 1; $page <= $pages; $page++) {
                $loc = $this->escape(url($this->fileName($handle, $page)));
                $body .= "  <sitemap>\n    <loc>{$loc}</loc>\n  </sitemap>\n";
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body
            .'</sitemapindex>'."\n";

        if ($complete) {
            Cache::put('seo:sitemap:index', $xml, $this->ttl());
        }

        return $xml;
    }

    /**
     * One page of one source's child sitemap, or null when the source or the page
     * does not exist (a 404, not an empty sitemap — an empty file would tell a
     * crawler the content was removed).
     */
    public function child(string $handle, int $page = 1): ?string
    {
        $source = $this->registry->get($handle);

        if ($source === null || $page < 1) {
            return null;
        }

        $cached = Cache::get($this->pageKey($handle, $page));
        if (is_string($cached)) {
            return $cached;
        }

        $pages = $this->pageCount($handle, $source);

        if ($pages === null || $page > $pages) {
            return null;
        }

        $xml = Cache::get($this->pageKey($handle, $page));

        if (! is_string($xml)) {
            // The page count outlived its page bodies (independent eviction);
            // rebuild rather than 404 a page the index still advertises.
            $pages = $this->build($handle, $source);

            if ($pages === null || $page > $pages) {
                return null;
            }

            $xml = Cache::get($this->pageKey($handle, $page));
        }

        return is_string($xml) ? $xml : null;
    }

    /**
     * Drop every cached sitemap so the next request rebuilds from live content.
     * Called when content changes; cheaper and more predictable than tagged cache,
     * which several supported cache drivers do not implement at all.
     */
    public function purge(): void
    {
        Cache::forget('seo:sitemap:index');

        foreach (array_keys($this->registry->all()) as $handle) {
            $pages = Cache::get($this->countKey($handle));

            for ($page = 1; $page <= (is_int($pages) ? $pages : 0); $page++) {
                Cache::forget($this->pageKey($handle, $page));
            }

            Cache::forget($this->countKey($handle));
        }
    }

    /**
     * How many pages this source has, building and caching them if needed.
     * Null means the source failed and nothing about it should be published.
     */
    private function pageCount(string $handle, SeoSubjectSource $source): ?int
    {
        $cached = Cache::get($this->countKey($handle));
        if (is_int($cached)) {
            return $cached;
        }

        return $this->build($handle, $source);
    }

    /**
     * Stream a source once, writing each full page of URLs straight to the cache.
     * Returns the page count, or null when the source threw — in which case
     * nothing is cached, so the next request retries rather than serving a
     * truncated sitemap for the whole TTL.
     */
    private function build(string $handle, SeoSubjectSource $source): ?int
    {
        $perPage = $this->maxUrls();
        $pages = [];
        $current = '';
        $count = 0;

        try {
            $source->chunk(function (array $subjects) use ($source, &$pages, &$current, &$count, $perPage): void {
                foreach ($this->includable($subjects, $source) as $subject) {
                    $current .= $this->renderUrl($subject);
                    $count++;

                    if ($count % $perPage === 0) {
                        $pages[] = $current;
                        $current = '';
                    }
                }
            });
        } catch (Throwable $e) {
            // A faulty source must not 500 the sitemap for the whole site.
            Log::warning('SEO sitemap: a source failed while building.', [
                'source' => $handle,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        // Always emit at least one page, even when empty: the index references it,
        // and a 404 there would look like a broken sitemap rather than an empty one.
        if ($current !== '' || $pages === []) {
            $pages[] = $current;
        }

        foreach ($pages as $offset => $body) {
            Cache::put($this->pageKey($handle, $offset + 1), $this->urlset($body), $this->ttl());
        }

        Cache::put($this->countKey($handle), count($pages), $this->ttl());

        return count($pages);
    }

    /**
     * The subjects of one chunk that belong in a sitemap: indexable, with a URL,
     * and not pushed out of the index by a per-entity override. Overrides are
     * fetched for the whole chunk in one bulk read.
     *
     * @param  list<SeoSubject>  $subjects
     * @return list<SeoSubject>
     */
    private function includable(array $subjects, SeoSubjectSource $source): array
    {
        $candidates = array_values(array_filter(
            $subjects,
            static fn (SeoSubject $subject): bool => $subject->indexable && $subject->url !== '',
        ));

        $modelClass = $source->modelClass();

        if ($modelClass === null || $candidates === []) {
            return $candidates;
        }

        $pairs = [];
        foreach ($candidates as $subject) {
            if ($subject->modelId !== null && $subject->modelId !== '') {
                $pairs[] = [$modelClass, $subject->modelId];
            }
        }

        if ($pairs === []) {
            return $candidates;
        }

        $overrides = $this->meta->forMany($pairs);

        return array_values(array_filter($candidates, function (SeoSubject $subject) use ($overrides, $modelClass): bool {
            if ($subject->modelId === null || $subject->modelId === '') {
                return true;
            }

            $override = $overrides[$this->meta->key($modelClass, $subject->modelId)] ?? null;

            return $override === null || (bool) $override->getAttribute('robots_index') !== false;
        }));
    }

    private function urlset(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset '.self::URLSET_NS.'>'."\n"
            .$body
            .'</urlset>'."\n";
    }

    private function renderUrl(SeoSubject $subject): string
    {
        $out = "  <url>\n";
        $out .= '    <loc>'.$this->escape($subject->url)."</loc>\n";
        // lastmod comes from the content's own timestamp, never from build time,
        // so warming the cache does not tell crawlers the page changed.
        $out .= '    <lastmod>'.$this->escape($subject->updatedAt->format('c'))."</lastmod>\n";

        foreach (HreflangSet::forSubject($subject) as $alternate) {
            $out .= '    <xhtml:link rel="alternate" hreflang="'.$this->escape($alternate['hreflang'])
                .'" href="'.$this->escape($alternate['href']).'"/>'."\n";
        }

        foreach ($subject->images as $image) {
            $out .= "    <image:image>\n      <image:loc>".$this->escape($image->url)
                ."</image:loc>\n    </image:image>\n";
        }

        return $out."  </url>\n";
    }

    /**
     * Page 1 keeps the unsuffixed name so existing sitemap references and search
     * console submissions stay valid when a site grows past one page.
     */
    public function fileName(string $handle, int $page): string
    {
        return $page <= 1 ? "sitemap-{$handle}.xml" : "sitemap-{$handle}-{$page}.xml";
    }

    private function pageKey(string $handle, int $page): string
    {
        return 'seo:sitemap:child:'.$handle.':'.$page;
    }

    private function countKey(string $handle): string
    {
        return 'seo:sitemap:pages:'.$handle;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function ttl(): int
    {
        $ttl = config('seo.sitemap.cache_ttl');

        return is_int($ttl) ? $ttl : 3600;
    }

    private function maxUrls(): int
    {
        $max = config('seo.sitemap.max_urls');

        return is_int($max) && $max > 0 ? min($max, 50000) : 5000;
    }
}
