<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Magna\Seo\Dashboard\DashboardSummary;
use Magna\Seo\Jobs\RunSiteScanJob;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Scan\ScanSummary;
use Magna\Seo\Support\FixInstructions;
use Magna\Seo\Support\FixRoute;
use Magna\Testing\PluginTestCase;

/**
 * The dashboard's two claims about work: which page to open next, and whether
 * background work is happening at all.
 *
 * The second exists because of a real failure. The dashboard used to queue its
 * scan and report "Scan queued" — so on an install with no worker running, the
 * button did nothing, said it had worked, and left nineteen jobs sitting in the
 * table for three weeks. Silence is the worst possible response to a broken
 * queue, so it is now detected and stated.
 */
final class DashboardActionsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    private function scanWithIssues(): SeoScan
    {
        $scan = SeoScan::query()->create(['url_count' => 3, 'issue_count' => 4, 'score' => 60]);

        // One page with an error and a notice, one with a single notice. The
        // first must outrank the second on severity, not on volume.
        $scan->issues()->createMany([
            [
                'source' => 'blog', 'subject_key' => 'blog:1', 'url' => 'https://site.test/a',
                'check' => 'missing-title', 'severity' => 'notice', 'message' => 'No title.',
            ],
            [
                'source' => 'blog', 'subject_key' => 'blog:1', 'url' => 'https://site.test/a',
                'check' => 'schema-invalid', 'severity' => 'error', 'message' => 'Bad schema.',
            ],
            [
                'source' => 'blog', 'subject_key' => 'blog:2', 'url' => 'https://site.test/b',
                'check' => 'missing-description', 'severity' => 'notice', 'message' => 'No description.',
            ],
            [
                'source' => 'site', 'subject_key' => 'site', 'url' => null,
                'check' => 'robots-sitemap-conflict', 'severity' => 'warning', 'message' => 'Conflict.',
            ],
        ]);

        return $scan;
    }

    public function test_findings_group_by_page_with_the_worst_page_first(): void
    {
        $pages = app(ScanSummary::class)->issuesByPage($this->scanWithIssues());

        $this->assertCount(3, $pages);

        // Error beats warning beats notice, regardless of how many notices.
        $this->assertSame('/a', $pages[0]['title']);
        $this->assertSame('error', $pages[0]['worst']);
        $this->assertSame(2, $pages[0]['count']);

        $this->assertSame('warning', $pages[1]['worst']);

        // A site-level finding has no URL and must still appear rather than
        // vanishing into an unnamed bucket.
        $this->assertSame('Site-wide', $pages[1]['title']);
        $this->assertNull($pages[1]['url']);
    }

    public function test_a_page_grouping_keeps_one_pages_problems_together(): void
    {
        $pages = app(ScanSummary::class)->issuesByPage($this->scanWithIssues());

        $checks = array_map(static fn ($issue): string => $issue->check, $pages[0]['issues']);

        sort($checks);
        $this->assertSame(['missing-title', 'schema-invalid'], $checks);
    }

    public function test_bulk_fix_routes_exist_only_where_the_fix_really_is(): void
    {
        $route = new FixRoute;

        // Clearable in bulk on the content list, and pointed at the filter that
        // isolates it. The URL itself needs a booted panel, so the mapping is
        // asserted directly — it is the half that goes stale.
        $this->assertSame('no-description', $route->filterFor('missing-description'));
        $this->assertSame('no-keyword', $route->filterFor('keyword-in-opening'));
        $this->assertSame('needs-work', $route->filterFor('thin-content'));

        // Redirect problems belong to the redirect screen, not the content list.
        $this->assertTrue($route->isRedirectCheck('redirect-chain'));
        $this->assertTrue($route->isRedirectCheck('sitemap-redirect'));
        $this->assertNull($route->filterFor('redirect-chain'));

        // A check with no bulk destination must produce no button. One that
        // lands somewhere unable to fix the thing is worse than none at all.
        $this->assertNull($route->filterFor('missing-image-alt'));
        $this->assertNull($route->filterFor('heavy-page'));
        $this->assertNull($route->for('missing-image-alt'));
    }

    public function test_an_empty_queue_is_treated_as_healthy(): void
    {
        config()->set('queue.default', 'database');

        $health = app(DashboardSummary::class)->queueHealth();

        // Nothing waiting proves nothing either way, so it must not cry wolf.
        $this->assertTrue($health['healthy']);
        $this->assertSame(0, $health['pending']);
    }

    public function test_jobs_left_sitting_are_reported_as_a_stalled_queue(): void
    {
        config()->set('queue.default', 'database');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time() - 3600,
        ]);

        $health = app(DashboardSummary::class)->queueHealth();

        $this->assertFalse($health['healthy']);
        $this->assertSame(1, $health['pending']);
        $this->assertGreaterThanOrEqual(59, $health['stalledMinutes']);
        $this->assertFalse(app(DashboardSummary::class)->queueIsProcessing());
    }

    public function test_a_sync_queue_is_never_reported_as_stalled(): void
    {
        // Sync runs on dispatch, so a backlog is impossible by construction.
        config()->set('queue.default', 'sync');

        $this->assertTrue(app(DashboardSummary::class)->queueIsProcessing());
    }

    public function test_every_bulk_fixable_check_is_a_real_check(): void
    {
        // A check renamed in the scanner would otherwise leave a fix button
        // pointing at a problem no longer reported under that id, and nothing
        // would say so. This has already happened once, with
        // keyword-in-first-paragraph against the real keyword-in-opening.
        foreach ((new FixRoute)->mappedChecks() as $check) {
            $this->assertNotNull(
                FixInstructions::for($check),
                "FixRoute maps [{$check}], which has no fix instructions and is probably not a real check id.",
            );
        }
    }

    public function test_the_dashboard_can_drain_a_stalled_queue(): void
    {
        config()->set('queue.default', 'database');

        Bus::dispatch(new RunSiteScanJob);

        $this->assertSame(1, DB::table('jobs')->count());

        // Bounded and synchronous. A button cannot responsibly start a real
        // worker daemon — it would die with the request or outlive it
        // unsupervised — but it can clear what is already waiting.
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 20,
        ]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue(app(DashboardSummary::class)->queueIsProcessing());
    }
}
