<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Scan\ScanSummary;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Support\FindingLink;
use Magna\Testing\PluginTestCase;

final class ScanSummaryTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');

        // The repo ships a static public/robots.txt; point the shadowing check at a
        // path that does not exist so these fixtures test content, not the tree.
        config()->set('seo.robots.static_path', __DIR__.'/no-robots.txt');
    }

    private function publish(string $slug, string $title): void
    {
        DocPage::create([
            'title' => $title,
            'slug' => $slug,
            'content' => str_repeat('lorem ipsum dolor sit ', 40),
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_it_summarises_the_latest_scan(): void
    {
        $this->publish('a', 'Shared Title');
        $this->publish('b', 'Shared Title');
        $this->app->make(SiteScanner::class)->scan();

        $summary = $this->app->make(ScanSummary::class);
        $scan = $summary->latest();

        $this->assertNotNull($scan);
        $this->assertSame(['warning' => 2], $summary->countsBySeverity($scan));
        $this->assertSame(['duplicate-title' => 2], $summary->countsByCheck($scan));
        $this->assertCount(2, $summary->recentIssues($scan));
    }

    public function test_latest_is_null_before_any_scan(): void
    {
        $this->assertNull($this->app->make(ScanSummary::class)->latest());
    }

    /**
     * The per-issue "fix" link, now shared by the dashboard and the Health page
     * so the same finding cannot offer two different destinations.
     */
    public function test_each_finding_links_to_where_it_is_fixed(): void
    {
        $links = new FindingLink;

        // Outside a booted panel the redirect screen has no URL, and the link
        // degrades instead of failing the page rendering it.
        $this->assertNull($links->for(new SeoScanIssue(['check' => 'redirect-chain', 'url' => '/a'])));

        $robotsIssue = new SeoScanIssue(['check' => 'robots-sitemap-conflict', 'url' => '/robots.txt']);
        $this->assertSame('robots.txt', $links->for($robotsIssue)['label']);

        $pageIssue = new SeoScanIssue(['check' => 'missing-title', 'url' => 'https://site.test/p']);
        $this->assertSame('https://site.test/p', $links->for($pageIssue)['url']);

        // A finding with no URL of its own offers no link rather than a broken one.
        $this->assertNull($links->for(new SeoScanIssue(['check' => 'missing-title', 'url' => null])));
    }
}
