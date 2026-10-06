<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * robots.txt and the sitemaps contradicting each other.
 *
 * Two ways this happens in practice, and both are silent:
 *
 * 1. A static `public/robots.txt` is served by the web server before PHP runs,
 *    shadowing the dynamic route entirely — so whatever it says (often a stale
 *    `Disallow: /` from a staging deploy) is what crawlers obey, no matter what
 *    the SEO settings say. `seo:robots` backs that file out.
 * 2. The site is advertising sitemaps full of URLs while robots.txt disallows
 *    crawling them, which is the normal state outside production and a disaster
 *    inside it.
 */
final class RobotsSitemapConflictCheck implements ScanCheck
{
    /**
     * @param  string|null  $staticRobotsPath  Where the shadowing file would live;
     *                                         null falls back to public/robots.txt.
     * @param  bool  $checkStaticFile  False on an install that manages robots.txt
     *                                 outside the application entirely.
     */
    public function __construct(
        private readonly bool $isProduction,
        private readonly ?string $staticRobotsPath = null,
        private readonly bool $checkStaticFile = true,
    ) {}

    public function run(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $issues = [];
        $static = $this->staticRobots();

        if ($static !== null && $this->disallowsEverything($static)) {
            $issues[] = new ScanIssue(
                source: 'robots',
                subjectKey: 'robots:static',
                url: '/robots.txt',
                check: 'robots-sitemap-conflict',
                severity: Severity::Error,
                message: 'A static public/robots.txt disallows all crawling and shadows the dynamic one; run "php artisan seo:robots" to back it out.',
            );
        } elseif ($this->isProduction && $static !== null && ! str_contains($static, 'Sitemap:')) {
            // Only worth raising in production: outside it, the sitemap pointer is
            // not what a crawler would be acting on anyway.
            $issues[] = new ScanIssue(
                source: 'robots',
                subjectKey: 'robots:static',
                url: '/robots.txt',
                check: 'robots-sitemap-conflict',
                severity: Severity::Warning,
                message: 'A static public/robots.txt shadows the dynamic one and does not point at the sitemap index.',
            );
        }

        // Deliberately no finding for the non-production "Disallow: /": that is
        // the intended behaviour there, not a defect, and the scorecard already
        // asserts the environment is set up that way.

        return $issues;
    }

    private function staticRobots(): ?string
    {
        if (! $this->checkStaticFile) {
            return null;
        }

        $path = $this->staticRobotsPath ?? public_path('robots.txt');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function disallowsEverything(string $robots): bool
    {
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            if (preg_match('/^\s*disallow:\s*\/\s*$/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
