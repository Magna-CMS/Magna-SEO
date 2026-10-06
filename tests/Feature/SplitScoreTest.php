<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Seo\Analysis\AnalysisCategory;
use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\ContentAnalyser;
use Magna\Seo\Filament\SeoPanel;
use Magna\Testing\PluginTestCase;

/**
 * Phase 1: SEO and readability scored apart, and the live panel that shows them.
 */
final class SplitScoreTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    private function report(): AnalysisReport
    {
        // Strong on SEO signals, deliberately painful to read.
        $sentence = 'The implementation of the aforementioned configuration was subsequently '
            .'undertaken by the engineering department in a manner which was determined to be '
            .'consistent with the previously established organisational requirements and '
            .'expectations of the stakeholders involved throughout the process. ';

        return app(ContentAnalyser::class)->analyse(new AnalysisInput(
            keyword: 'coffee beans',
            title: 'Coffee beans: a buying guide',
            description: 'Everything about coffee beans, from origin to roast, in one practical guide for home brewers who want better results.',
            slug: 'coffee-beans',
            plainText: 'Coffee beans. '.str_repeat($sentence, 12),
            html: '<h1>Coffee beans</h1><p>Coffee beans. '.str_repeat($sentence, 12).'</p>'
                .'<a href="/brewing">Brewing</a><a href="https://ref.test">Source</a>'
                .'<img src="a.jpg" alt="Beans" width="10" height="10">',
            siteUrl: 'https://site.test/coffee-beans',
        ));
    }

    public function test_seo_and_readability_are_scored_separately(): void
    {
        $report = $this->report();

        $this->assertGreaterThan(0, $report->seoScore);
        $this->assertGreaterThan(0, $report->results !== [] ? 1 : 0);

        // The content is keyword-rich but written badly, so the two scores must
        // not be the same number — that is the whole point of splitting them.
        $this->assertNotSame($report->seoScore, $report->readabilityScore);
        $this->assertGreaterThan($report->readabilityScore, $report->seoScore);
    }

    public function test_the_blended_score_still_exists_for_callers_that_want_one_number(): void
    {
        $report = $this->report();

        $this->assertGreaterThanOrEqual(0, $report->score);
        $this->assertLessThanOrEqual(100, $report->score);
    }

    public function test_results_are_grouped_by_category_and_none_are_lost(): void
    {
        $report = $this->report();

        $seo = $report->resultsFor(AnalysisCategory::Seo);
        $readability = $report->resultsFor(AnalysisCategory::Readability);

        $this->assertNotEmpty($seo);
        $this->assertNotEmpty($readability);
        $this->assertCount(count($report->results), array_merge($seo, $readability));
    }

    public function test_every_shipped_check_has_an_explicit_category(): void
    {
        $probe = new AnalysisInput('kw', 'Title', 'Description', 'slug', 'Some body text.', '<p>Some body text.</p>');

        foreach (app(ContentAnalyser::class)->checkIds($probe) as $check) {
            $this->assertTrue(
                AnalysisCategory::isMapped($check),
                "Check [{$check}] is not assigned to a category in AnalysisCategory.",
            );
        }
    }

    public function test_the_traffic_light_bands_match_the_convention_editors_know(): void
    {
        $this->assertSame(AnalysisStatus::Bad, AnalysisReport::band(40));
        $this->assertSame(AnalysisStatus::Ok, AnalysisReport::band(41));
        $this->assertSame(AnalysisStatus::Ok, AnalysisReport::band(70));
        $this->assertSame(AnalysisStatus::Good, AnalysisReport::band(71));

        // Colour is never the only signal.
        $this->assertSame('Needs work', AnalysisReport::bandLabel(10));
        $this->assertSame('OK', AnalysisReport::bandLabel(60));
        $this->assertSame('Good', AnalysisReport::bandLabel(90));
    }

    public function test_the_serialised_report_carries_both_scores_and_each_category(): void
    {
        $array = $this->report()->toArray();

        $this->assertArrayHasKey('seo_score', $array);
        $this->assertArrayHasKey('readability_score', $array);
        $this->assertContains($array['results'][0]['category'], ['seo', 'readability']);
    }

    public function test_an_empty_category_scores_zero_rather_than_dividing_by_zero(): void
    {
        $report = AnalysisReport::fromResults([
            new AnalysisResult('keyword-in-title', AnalysisStatus::Good, 'Fine.'),
        ]);

        $this->assertSame(100, $report->seoScore);
        $this->assertSame(0, $report->readabilityScore);
    }

    public function test_the_live_panel_rescores_when_the_form_reports_a_change(): void
    {
        $panel = new SeoPanel;

        $panel->syncFromForm([
            'title' => 'Untitled',
            'description' => '',
            'slug' => '',
            'body' => '<p>Hi.</p>',
            'keyword' => 'coffee beans',
        ]);

        $before = $panel->report()->seoScore;

        $panel->syncFromForm([
            'title' => 'Coffee beans: a buying guide',
            'description' => 'A practical guide to choosing coffee beans, covering origin, roast level and freshness for home brewers.',
            'slug' => 'coffee-beans',
            'body' => '<h1>Coffee beans</h1><p>'.str_repeat('Coffee beans are graded by origin and roast. ', 80).'</p>'
                .'<a href="/brewing">Brewing</a>',
            'keyword' => 'coffee beans',
        ]);

        $this->assertGreaterThan($before, $panel->report()->seoScore);
    }

    public function test_the_panel_exposes_both_categories_with_a_band_and_a_label(): void
    {
        $panel = new SeoPanel;
        $panel->syncFromForm(['title' => 'T', 'description' => 'D', 'slug' => 's', 'body' => '<p>Body.</p>', 'keyword' => '']);

        $categories = $panel->categories();

        $this->assertSame(['seo', 'readability'], array_keys($categories));
        $this->assertSame('SEO', $categories['seo']['label']);
        $this->assertContains($categories['seo']['band'], ['bad', 'ok', 'good']);
        $this->assertNotSame('', $categories['readability']['bandLabel']);
    }

    public function test_analysis_of_a_long_page_stays_inside_its_latency_budget(): void
    {
        $panel = new SeoPanel;
        $panel->syncFromForm([
            'title' => 'Long page',
            'description' => 'A long page.',
            'slug' => 'long',
            'body' => '<h1>Long</h1>'.str_repeat('<p>'.str_repeat('word ', 60).'</p>', 50),
            'keyword' => 'long',
        ]);

        $started = microtime(true);
        $panel->report();
        $elapsed = (microtime(true) - $started) * 1000;

        // The live panel re-analyses on every debounced keystroke burst; a 3 000
        // word page must not make that feel slow.
        $this->assertLessThan(250, $elapsed, "Analysis took {$elapsed}ms.");
    }
}
