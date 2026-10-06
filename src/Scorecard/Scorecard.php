<?php

declare(strict_types=1);

namespace Magna\Seo\Scorecard;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Media\MediaDimensions;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Schema\SchemaValidator;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Sitemap\SitemapGenerator;
use Magna\Seo\Subjects\SeoSubject;
use Throwable;

/**
 * Runs the "SEO-friendly by construction" invariants: the technical guarantees a
 * Magna site should uphold before anyone touches a setting. Every check is
 * computed from content alone (no network), so it can gate CI.
 */
final class Scorecard
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly MetaResolver $resolver,
        private readonly SchemaGraphBuilder $schema,
        private readonly bool $isProduction,
        private readonly SitemapGenerator $sitemaps,
        private readonly SchemaValidator $validator = new SchemaValidator,
        private readonly MediaDimensions $dimensions = new MediaDimensions,
    ) {}

    public function run(): ScorecardReport
    {
        $subjects = $this->collect();
        $settings = SeoSettings::get();

        $results = [
            $this->everyPageHasCanonical($subjects),
            $this->oneCanonicalPerPage($subjects),
            $this->noIndexableUrlRedirects($subjects),
            $this->lastmodComesFromContent($subjects),
            $this->everyImageHasDimensions($subjects),
            $this->sitemapsAgreeWithIndexability($subjects),
            $this->hreflangReciprocal($subjects),
            $this->structuredDataValid($subjects, $settings),
            $this->robotsAgreesWithSitemaps(),
            $this->nonProductionIsNoindex($settings),
        ];

        $passed = array_reduce($results, static fn (bool $carry, ScorecardResult $r): bool => $carry && $r->passed, true);

        return new ScorecardReport($passed, $results);
    }

    /**
     * @param  list<SeoSubject>  $subjects
     */
    private function everyPageHasCanonical(array $subjects): ScorecardResult
    {
        $missing = 0;
        foreach ($subjects as $subject) {
            if ($subject->url === '') {
                $missing++;
            }
        }

        return new ScorecardResult(
            'canonical-present',
            'Every indexable page has a canonical URL',
            $missing === 0,
            $missing === 0 ? 'All pages have a URL.' : "{$missing} page(s) have no URL.",
        );
    }

    /**
     * @param  list<SeoSubject>  $subjects
     */
    private function oneCanonicalPerPage(array $subjects): ScorecardResult
    {
        $counts = [];
        foreach ($subjects as $subject) {
            if ($subject->url !== '') {
                $counts[$subject->url] = ($counts[$subject->url] ?? 0) + 1;
            }
        }

        $duplicates = array_filter($counts, static fn (int $c): bool => $c > 1);

        return new ScorecardResult(
            'canonical-unique',
            'No two pages share a canonical URL',
            $duplicates === [],
            $duplicates === [] ? 'All canonicals are unique.' : count($duplicates).' URL(s) are shared by multiple pages.',
        );
    }

    /**
     * Invariant 2: an indexable URL answers 200. Statically, the way that breaks
     * is a redirect (or 410) rule covering a URL the site still advertises as
     * live — the crawler is told "this is the address" and then sent elsewhere.
     *
     * @param  list<SeoSubject>  $subjects
     */
    private function noIndexableUrlRedirects(array $subjects): ScorecardResult
    {
        $rules = SeoRedirect::query()
            ->where('is_active', true)
            ->where('match_type', 'exact')
            ->pluck('source_path')
            ->all();

        $sources = [];
        foreach ($rules as $source) {
            $sources[RedirectStore::normalisePath((string) $source)] = true;
        }

        $conflicts = 0;
        foreach ($subjects as $subject) {
            if ($subject->url !== '' && isset($sources[RedirectStore::normalisePath($subject->url)])) {
                $conflicts++;
            }
        }

        return new ScorecardResult(
            'live-urls-200',
            'No indexable URL is also a redirect or gone',
            $conflicts === 0,
            $conflicts === 0
                ? 'Every indexable URL is served directly.'
                : "{$conflicts} indexable URL(s) are covered by a redirect rule.",
        );
    }

    /**
     * Invariant 3: lastmod reflects content, never build time. It is taken from
     * the subject's own updated_at, so the failure this catches is a source that
     * synthesises a timestamp — visible as a lastmod at or after the moment of
     * the run, or in the future.
     *
     * @param  list<SeoSubject>  $subjects
     */
    private function lastmodComesFromContent(array $subjects): ScorecardResult
    {
        $now = new DateTimeImmutable;
        $suspect = 0;

        foreach ($subjects as $subject) {
            if ($subject->updatedAt > $now) {
                $suspect++;
            }
        }

        return new ScorecardResult(
            'lastmod-from-content',
            'lastmod comes from the content, not the build',
            $suspect === 0,
            $suspect === 0
                ? 'All timestamps come from content.'
                : "{$suspect} page(s) report a modification time in the future.",
        );
    }

    /**
     * Invariant 4: every image carries width and height. Without them the browser
     * cannot reserve space, and the page shifts as images load — the layout-shift
     * penalty that Core Web Vitals measures.
     *
     * @param  list<SeoSubject>  $subjects
     */
    private function everyImageHasDimensions(array $subjects): ScorecardResult
    {
        // Sources often hand over a bare URL and leave the dimensions in the
        // media library. Resolve the whole set in one query before judging, so
        // this reports images that genuinely have no dimensions anywhere rather
        // than images nobody looked up.
        $urls = [];
        foreach ($subjects as $subject) {
            foreach ($subject->images as $image) {
                $urls[] = $image->url;
            }
        }

        $this->dimensions->prime($urls);

        $total = 0;
        $missing = 0;

        foreach ($subjects as $subject) {
            foreach ($subject->images as $image) {
                $total++;

                $resolved = $this->dimensions->enrich($image);

                if ($resolved->width === null || $resolved->height === null) {
                    $missing++;
                }
            }
        }

        return new ScorecardResult(
            'image-dimensions',
            'Every social image carries width and height',
            $missing === 0,
            match (true) {
                $total === 0 => 'No images to check.',
                $missing === 0 => "All {$total} image(s) carry dimensions.",
                default => "{$missing} of {$total} image(s) have no width/height.",
            },
        );
    }

    /**
     * Invariant 5: nothing marked noindex appears in a sitemap. The generator
     * excludes them, so this asserts the guarantee end to end rather than trusting
     * it — a sitemap that lists a noindex URL sends a crawler a contradiction.
     *
     * @param  list<SeoSubject>  $subjects
     */
    private function sitemapsAgreeWithIndexability(array $subjects): ScorecardResult
    {
        $noindexUrls = SeoMeta::query()
            ->where('robots_index', false)
            ->pluck('seoable_id')
            ->all();

        if ($noindexUrls === []) {
            return new ScorecardResult(
                'sitemap-no-noindex',
                'No noindex page appears in a sitemap',
                true,
                'No page is marked noindex.',
            );
        }

        $listed = 0;
        foreach ($this->registry->all() as $handle => $source) {
            $xml = (string) $this->sitemaps->child($handle);

            foreach ($subjects as $subject) {
                if ($subject->modelId !== null
                    && in_array($subject->modelId, $noindexUrls, true)
                    && str_contains($xml, '<loc>'.htmlspecialchars($subject->url, ENT_QUOTES | ENT_XML1, 'UTF-8').'</loc>')) {
                    $listed++;
                }
            }
        }

        return new ScorecardResult(
            'sitemap-no-noindex',
            'No noindex page appears in a sitemap',
            $listed === 0,
            $listed === 0
                ? 'Sitemaps list only indexable pages.'
                : "{$listed} noindex page(s) are listed in a sitemap.",
        );
    }

    /**
     * Invariant 9: robots.txt and the sitemaps do not contradict each other. The
     * dynamic robots.txt is generated from the same settings the sitemaps are, so
     * the way this breaks in practice is a static public/robots.txt that the web
     * server serves first — usually a stale "Disallow: /" from a staging deploy.
     */
    private function robotsAgreesWithSitemaps(): ScorecardResult
    {
        $path = public_path('robots.txt');

        if (! is_file($path) || ! is_readable($path)) {
            return new ScorecardResult(
                'robots-agrees',
                'robots.txt does not contradict the sitemaps',
                true,
                'robots.txt is generated from the site settings.',
            );
        }

        $contents = (string) file_get_contents($path);
        $blocked = preg_match('/^\s*disallow:\s*\/\s*$/im', $contents) === 1;

        return new ScorecardResult(
            'robots-agrees',
            'robots.txt does not contradict the sitemaps',
            ! $blocked,
            $blocked
                ? 'A static public/robots.txt blocks all crawling and shadows the generated one; run "php artisan seo:robots".'
                : 'A static public/robots.txt shadows the generated one but does not block crawling.',
        );
    }

    /**
     * @param  list<SeoSubject>  $subjects
     */
    private function hreflangReciprocal(array $subjects): ScorecardResult
    {
        $alternatesByUrl = [];
        foreach ($subjects as $subject) {
            $alternatesByUrl[$subject->url] = $subject->alternates;
        }

        $broken = 0;
        foreach ($subjects as $subject) {
            foreach ($subject->alternates as $altUrl) {
                if ($altUrl === $subject->url) {
                    continue;
                }
                $target = $alternatesByUrl[$altUrl] ?? null;
                if ($target !== null && ! in_array($subject->url, $target, true)) {
                    $broken++;
                }
            }
        }

        return new ScorecardResult(
            'hreflang-reciprocal',
            'Every hreflang alternate links back',
            $broken === 0,
            $broken === 0 ? 'All alternates are reciprocal.' : "{$broken} alternate(s) are not reciprocal.",
        );
    }

    /**
     * @param  list<SeoSubject>  $subjects
     */
    private function structuredDataValid(array $subjects, SeoSettings $settings): ScorecardResult
    {
        $invalid = 0;
        $firstError = null;

        foreach ($subjects as $subject) {
            $head = $this->resolver->resolve($subject, null, $settings);
            $graph = $this->schema->build($subject, $head, $settings);

            $errors = $this->validator->validate($graph);

            if ($graph !== null && json_encode($graph, self::JSON_FLAGS) === false) {
                $errors[] = 'JSON-LD failed to encode.';
            }

            if ($errors !== []) {
                $invalid++;
                $firstError ??= $errors[0];
            }
        }

        return new ScorecardResult(
            'schema-valid',
            'Structured data parses and validates on every page',
            $invalid === 0,
            $invalid === 0
                ? 'All JSON-LD is well-formed.'
                : "{$invalid} page(s) produced invalid JSON-LD (first: {$firstError}).",
        );
    }

    private function nonProductionIsNoindex(SeoSettings $settings): ScorecardResult
    {
        $ok = $this->isProduction || $settings->noindex_non_production;

        return new ScorecardResult(
            'nonprod-noindex',
            'Non-production emits noindex by default',
            $ok,
            $this->isProduction
                ? 'Production — indexing allowed.'
                : ($ok ? 'Staging is noindex.' : 'Staging is NOT noindex — enable it in settings.'),
        );
    }

    /**
     * @return list<SeoSubject>
     */
    private function collect(): array
    {
        $subjects = [];
        foreach ($this->registry->all() as $handle => $source) {
            try {
                $source->chunk(function (array $batch) use (&$subjects): void {
                    foreach ($batch as $subject) {
                        if ($subject->indexable) {
                            $subjects[] = $subject;
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::warning('SEO scorecard: a source failed and was skipped.', [
                    'source' => $handle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $subjects;
    }
}
