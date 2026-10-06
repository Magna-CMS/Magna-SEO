<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Analysis\DocumentOutline;
use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Pages nothing links to. A crawler discovers content by following links, so an
 * orphan is reachable only from the sitemap — which is enough to be indexed but
 * not enough to be treated as important, because it inherits no internal
 * authority from the rest of the site.
 *
 * The link graph is built from the body markup sources expose as `raw['html']`.
 * If no source exposes markup there is no graph to reason about, and the check
 * reports nothing rather than declaring every page an orphan.
 */
final class OrphanPageCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        $linked = [];
        $sawMarkup = false;

        foreach ($subjects as $scanned) {
            $html = $scanned->subject->raw['html'] ?? null;

            if (! is_string($html) || $html === '') {
                continue;
            }

            $sawMarkup = true;
            $outline = new DocumentOutline($html, $scanned->subject->url);

            foreach ($outline->links as $link) {
                if ($link['internal']) {
                    $linked[RedirectStore::normalisePath($link['href'])] = true;
                }
            }
        }

        if (! $sawMarkup) {
            return [];
        }

        $issues = [];

        foreach ($subjects as $scanned) {
            $subject = $scanned->subject;
            $path = RedirectStore::normalisePath($subject->url);

            // The home page is reached directly and is never an orphan.
            if ($subject->url === '' || $path === '/' || isset($linked[$path])) {
                continue;
            }

            $issues[] = new ScanIssue(
                source: $scanned->source,
                subjectKey: $subject->key,
                url: $subject->url,
                check: 'orphan-page',
                severity: Severity::Warning,
                message: 'No other page links here, so this page inherits no internal authority.',
            );
        }

        return $issues;
    }
}
