<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Dashboard\DashboardSummary;
use Magna\Seo\Integrations\SearchConsole;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Settings\SeoSettings;
use Magna\Testing\PluginTestCase;

/**
 * Phase 2: the dashboard's numbers. The page itself is a view over these, so the
 * arithmetic is asserted here rather than through a panel render.
 */
final class DashboardTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
        config()->set('seo.robots.static_path', __DIR__.'/no-robots.txt');
        Cache::flush();
    }

    private function summary(): DashboardSummary
    {
        return $this->app->make(DashboardSummary::class);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function scan(int $score, int $urls = 10, array $stats = []): SeoScan
    {
        return SeoScan::query()->create([
            'url_count' => $urls,
            'issue_count' => array_sum($stats),
            'score' => $score,
            'check_stats' => $stats,
        ]);
    }

    private function configureSearchConsole(): void
    {
        $settings = SeoSettings::get();
        $settings->search_console_site = 'https://example.com/';
        $settings->search_console_client_id = 'id';
        $settings->search_console_client_secret = 'secret';
        $settings->search_console_refresh_token = 'refresh';
        $settings->save();
    }

    public function test_a_scan_records_the_scorecard_score_and_failures_per_check(): void
    {
        DocPage::create([
            'title' => 'Guide', 'slug' => 'guide', 'content' => str_repeat('word ', 200),
            'status' => 'published', 'is_published' => true, 'published_at' => now(),
        ]);

        $scan = $this->app->make(SiteScanner::class)->scan();

        $this->assertNotNull($scan->score, 'the scorecard result is stored with the scan');
        $this->assertGreaterThanOrEqual(0, $scan->score);
        $this->assertLessThanOrEqual(100, $scan->score);
        $this->assertIsArray($scan->check_stats);
    }

    public function test_failures_are_counted_per_page_not_per_issue(): void
    {
        $scan = $this->scan(80, urls: 4);

        // The same page failing the same check twice is one failing page.
        foreach ([['a', 'missing-title'], ['a', 'missing-title'], ['b', 'missing-title']] as [$subject, $check]) {
            SeoScanIssue::query()->create([
                'seo_scan_id' => $scan->id, 'source' => 'fake', 'subject_key' => $subject,
                'url' => 'https://site.test/'.$subject, 'check' => $check,
                'severity' => Severity::Error->value, 'message' => 'x',
            ]);
        }

        $recounted = $this->scan(80, urls: 4, stats: ['missing-title' => 2]);

        $this->assertSame(50, $recounted->passRateFor('missing-title'));
    }

    public function test_health_reports_movement_since_the_previous_scan(): void
    {
        $this->scan(70);
        $this->scan(85);

        $health = $this->summary()->health();

        $this->assertSame(85, $health['score']);
        $this->assertSame(15, $health['change']);
        $this->assertNotNull($health['scannedAt']);
    }

    public function test_health_is_honest_before_any_scan_has_run(): void
    {
        $health = $this->summary()->health();

        $this->assertNull($health['score']);
        $this->assertNull($health['change']);
        $this->assertSame(0, $health['urls']);
    }

    public function test_the_checklist_sorts_the_worst_check_first(): void
    {
        $this->scan(60, urls: 10, stats: ['missing-title' => 1, 'missing-description' => 8]);

        $checklist = $this->summary()->checklist();

        $this->assertSame('missing-description', $checklist[0]['check']);
        $this->assertSame(20, $checklist[0]['rate']);
        $this->assertSame(8, $checklist[0]['failed']);
    }

    public function test_the_start_here_card_names_the_most_common_problem_and_its_remedy(): void
    {
        $this->scan(60, urls: 10, stats: ['missing-title' => 2, 'missing-image-alt' => 7]);

        $issues = $this->summary()->issues();

        $this->assertSame('missing-image-alt', $issues['topCheck']['check']);
        $this->assertSame(7, $issues['topCheck']['count']);
        $this->assertNotNull($issues['topCheck']['fix']);
    }

    public function test_search_reports_not_connected_rather_than_zero(): void
    {
        Http::fake();

        $search = $this->summary()->search();

        $this->assertFalse($search['connected']);
        $this->assertNull($search['totals'], 'zero clicks and "we cannot see your clicks" are different statements');
        Http::assertNothingSent();
    }

    public function test_site_totals_carry_the_previous_window_for_comparison(): void
    {
        $this->configureSearchConsole();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'searchconsole.googleapis.com/*' => Http::sequence()
                ->push(['rows' => [['clicks' => 120, 'impressions' => 3000, 'ctr' => 0.04, 'position' => 8.2]]])
                ->push(['rows' => [['clicks' => 90, 'impressions' => 2500, 'ctr' => 0.036, 'position' => 9.6]]]),
        ]);

        $totals = $this->app->make(SearchConsole::class)->siteTotals();

        $this->assertSame(120, $totals['clicks']);
        $this->assertSame(90, $totals['previous']['clicks']);
    }

    public function test_striking_distance_surfaces_queries_just_off_page_one(): void
    {
        $queries = [
            ['query' => 'on page one', 'position' => 4.0, 'impressions' => 900, 'clicks' => 40],
            ['query' => 'nearly there', 'position' => 12.4, 'impressions' => 800, 'clicks' => 3],
            ['query' => 'also nearly', 'position' => 18.0, 'impressions' => 200, 'clicks' => 1],
            ['query' => 'far away', 'position' => 44.0, 'impressions' => 5000, 'clicks' => 0],
        ];

        $near = $this->summary()->strikingDistance($queries);

        $this->assertCount(2, $near);
        // Ordered by impressions: the biggest opportunity first.
        $this->assertSame('nearly there', $near[0]['query']);
        $this->assertSame('also nearly', $near[1]['query']);
    }

    public function test_striking_distance_is_empty_without_search_console(): void
    {
        $this->assertSame([], $this->summary()->strikingDistance(null));
    }
}
