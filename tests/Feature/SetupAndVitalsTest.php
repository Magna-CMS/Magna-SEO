<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\BreadcrumbRenderer;
use Magna\Seo\Integrations\CoreWebVitals;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoCrumb;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Testing\PluginTestCase;

/**
 * Phase 5: Core Web Vitals field data, rendered breadcrumbs, and the first-run
 * checklist.
 */
final class SetupAndVitalsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        Cache::flush();
    }

    private function withKey(): void
    {
        $settings = SeoSettings::get();
        $settings->crux_api_key = 'crux-key';
        $settings->save();
    }

    /**
     * @param  list<SeoCrumb>  $crumbs
     */
    private function subject(array $crumbs): SeoSubject
    {
        return new SeoSubject(
            key: 'k',
            type: SubjectType::Page,
            url: 'https://site.test/docs/install',
            title: 'Install',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
            breadcrumbs: $crumbs,
        );
    }

    public function test_core_web_vitals_are_inert_until_a_key_is_configured(): void
    {
        Http::fake();

        $vitals = new CoreWebVitals;

        $this->assertFalse($vitals->isConfigured());
        $this->assertNull($vitals->forUrl('https://site.test/p'));
        Http::assertNothingSent();
    }

    public function test_it_rates_each_metric_against_googles_thresholds(): void
    {
        $this->withKey();

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response([
            'record' => ['metrics' => [
                'largest_contentful_paint' => ['percentiles' => ['p75' => 1800]],
                'interaction_to_next_paint' => ['percentiles' => ['p75' => 320]],
                'cumulative_layout_shift' => ['percentiles' => ['p75' => 0.31]],
            ]],
        ])]);

        $metrics = (new CoreWebVitals)->forUrl('https://site.test/p');

        $this->assertSame('good', $metrics['largest_contentful_paint']['rating']);
        $this->assertSame('needs-improvement', $metrics['interaction_to_next_paint']['rating']);
        $this->assertSame('poor', $metrics['cumulative_layout_shift']['rating']);
        $this->assertSame('Largest Contentful Paint', $metrics['largest_contentful_paint']['label']);
    }

    public function test_a_page_with_too_little_traffic_reports_nothing_and_is_not_re_asked(): void
    {
        $this->withKey();

        // 404 is CrUX's normal answer for a quiet URL, not an error.
        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response('', 404)]);

        $vitals = new CoreWebVitals;

        $this->assertNull($vitals->forUrl('https://site.test/quiet'));
        $this->assertNull($vitals->forUrl('https://site.test/quiet'));

        Http::assertSentCount(1);
    }

    public function test_results_are_cached_so_a_dashboard_load_costs_nothing(): void
    {
        $this->withKey();

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response([
            'record' => ['metrics' => ['largest_contentful_paint' => ['percentiles' => ['p75' => 1200]]]],
        ])]);

        $vitals = new CoreWebVitals;
        $vitals->forUrl('https://site.test/p');
        $vitals->forUrl('https://site.test/p');

        Http::assertSentCount(1);
    }

    public function test_an_outage_degrades_to_no_data(): void
    {
        $this->withKey();

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response('', 503)]);

        $this->assertNull((new CoreWebVitals)->forUrl('https://site.test/p'));
    }

    public function test_breadcrumbs_render_as_links_with_the_current_page_marked(): void
    {
        $html = (new BreadcrumbRenderer)->render($this->subject([
            new SeoCrumb('Home', 'https://site.test/'),
            new SeoCrumb('Docs', 'https://site.test/docs'),
            new SeoCrumb('Install'),
        ]));

        $this->assertStringContainsString('<nav aria-label="Breadcrumb">', $html);
        $this->assertStringContainsString('<a href="https://site.test/docs">Docs</a>', $html);
        // The last crumb is the current page: marked, and not a link to itself.
        $this->assertStringContainsString('<span aria-current="page">Install</span>', $html);
        $this->assertStringNotContainsString('<a href="https://site.test/docs/install"', $html);
        // Separators are decorative and hidden from screen readers.
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_a_hostile_crumb_label_cannot_inject_markup(): void
    {
        $html = (new BreadcrumbRenderer)->render($this->subject([
            new SeoCrumb('<script>alert(1)</script>', 'https://site.test/"onmouseover="x'),
            new SeoCrumb('Here'),
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('onmouseover="x"', $html);
    }

    public function test_a_subject_with_no_trail_renders_nothing_at_all(): void
    {
        $this->assertSame('', (new BreadcrumbRenderer)->render($this->subject([])));
    }
}
