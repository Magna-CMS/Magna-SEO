<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * URLs that are advertised in a sitemap yet redirect or are gone.
 *
 * A sitemap is a statement that these URLs are the canonical, fetchable
 * addresses of the site's content. Listing one that answers 301 or 410 wastes
 * crawl budget and contradicts the site's own rules — usually because a page was
 * moved with a redirect while its old address stayed indexable.
 */
final class SitemapRedirectCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $rules = SeoRedirect::query()
            ->where('is_active', true)
            ->where('match_type', 'exact')
            ->pluck('status_code', 'source_path');

        if ($rules->isEmpty()) {
            return [];
        }

        /** @var array<string, int> $bySource */
        $bySource = [];

        foreach ($rules as $source => $status) {
            $bySource[RedirectStore::normalisePath((string) $source)] = (int) $status;
        }

        $issues = [];

        foreach ($subjects as $scanned) {
            $subject = $scanned->subject;

            if ($subject->url === '') {
                continue;
            }

            $status = $bySource[RedirectStore::normalisePath($subject->url)] ?? null;

            if ($status === null) {
                continue;
            }

            $issues[] = new ScanIssue(
                source: $scanned->source,
                subjectKey: $subject->key,
                url: $subject->url,
                check: 'sitemap-redirect',
                severity: Severity::Error,
                message: $status === 410
                    ? 'This URL is listed in a sitemap but a rule marks it gone (410).'
                    : "This URL is listed in a sitemap but a rule redirects it ({$status}); list the destination instead.",
            );
        }

        return $issues;
    }
}
