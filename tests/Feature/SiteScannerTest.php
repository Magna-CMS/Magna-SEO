<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

final class SiteScannerTest extends PluginTestCase
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

    private function publish(string $slug, string $title): DocPage
    {
        return DocPage::create([
            'title' => $title,
            'slug' => $slug,
            // Long enough not to trip the thin-content check (default 100 words).
            'content' => str_repeat('lorem ipsum dolor sit ', 40),
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_it_records_a_scan_and_flags_duplicate_titles(): void
    {
        $this->publish('a', 'Shared Title');
        $this->publish('b', 'Shared Title');

        $scan = $this->app->make(SiteScanner::class)->scan();

        $this->assertInstanceOf(SeoScan::class, $scan);
        $this->assertSame(2, $scan->url_count);
        $this->assertSame(2, $scan->issues()->where('check', 'duplicate-title')->count());
        $this->assertSame($scan->issue_count, $scan->issues()->count());

        $issue = $scan->issues()->firstOrFail();
        $this->assertSame(Severity::Warning, $issue->severity);
    }

    public function test_a_failing_source_is_skipped_not_fatal(): void
    {
        $this->publish('a', 'Alpha');

        // A source that throws while streaming must not abort the whole scan.
        $this->app->make(SeoSourceRegistry::class)
            ->register(new FakeSeoSubjectSource('boom', [], throwOnChunk: true));

        $scan = $this->app->make(SiteScanner::class)->scan();

        $this->assertInstanceOf(SeoScan::class, $scan);
        $this->assertSame(1, $scan->url_count); // the docs page was still scanned
    }

    public function test_a_clean_site_records_a_scan_with_no_issues(): void
    {
        $this->publish('a', 'Alpha');
        $this->publish('b', 'Beta');

        $scan = $this->app->make(SiteScanner::class)->scan();

        $this->assertSame(2, $scan->url_count);
        $this->assertSame(0, $scan->issue_count);
        $this->assertSame(0, $scan->issues()->count());
    }
}
