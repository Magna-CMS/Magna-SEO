<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\Checks\PageWeightCheck;
use Magna\Seo\Analysis\DocumentOutline;
use Magna\Seo\Analysis\PageWeight;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scan\Checks\HeavyPageCheck;
use Magna\Seo\Scan\KeywordCoverage;
use Magna\Seo\Scan\ScanDelta;
use Magna\Seo\Scan\ScannedSubject;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\FixInstructions;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

/**
 * Phase 4b: page weight, scan deltas, fix instructions and keyword coverage —
 * the machinery that turns findings into something a person acts on.
 */
final class ScanReportingTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    private function subject(string $id, string $html = '', string $title = 'Page'): ScannedSubject
    {
        return new ScannedSubject('fake', new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://site.test/'.$id,
            title: $title,
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
            raw: $html !== '' ? ['html' => $html] : [],
            modelId: $id,
        ));
    }

    private function issue(SeoScan $scan, string $check, string $subjectKey, Severity $severity = Severity::Error): SeoScanIssue
    {
        return SeoScanIssue::query()->create([
            'seo_scan_id' => $scan->id,
            'source' => 'fake',
            'subject_key' => $subjectKey,
            'url' => 'https://site.test/'.$subjectKey,
            'check' => $check,
            'severity' => $severity->value,
            'message' => 'Something is wrong.',
        ]);
    }

    public function test_page_weight_measures_markup_without_a_network_call(): void
    {
        $html = '<div><p>Hello</p><script>'.str_repeat('x', 2000).'</script>'
            .'<img src="a.jpg" width="10" height="10"><img src="b.jpg"></div>';

        $weight = PageWeight::of($html, new DocumentOutline($html));

        $this->assertSame(strlen($html), $weight->htmlBytes);
        $this->assertSame(2000, $weight->inlineScriptBytes);
        $this->assertSame(2, $weight->imageCount);
        $this->assertSame(1, $weight->imagesWithoutDimensions);
        $this->assertGreaterThan(3, $weight->domNodes);
    }

    public function test_a_heavy_page_is_reported_once_with_its_worst_problem(): void
    {
        $bloated = '<div>'.str_repeat('<p>text</p>', 20000).'</div>';

        $issues = (new HeavyPageCheck)->run([
            $this->subject('heavy', $bloated),
            $this->subject('light', '<p>Short and sweet.</p>'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('fake:heavy', $issues[0]->subjectKey);
        $this->assertSame('heavy-page', $issues[0]->check);
    }

    public function test_layout_shifting_images_are_flagged_in_the_editor(): void
    {
        $input = new AnalysisInput(
            keyword: '',
            title: 'T',
            description: 'D',
            slug: 'p',
            plainText: 'Body text.',
            html: '<p>Body text.</p><img src="hero.jpg">',
        );

        $result = (new PageWeightCheck)->analyse($input);

        $this->assertSame(AnalysisStatus::Bad, $result->status);
        $this->assertStringContainsString('no width and height', $result->message);
    }

    public function test_a_light_page_passes(): void
    {
        $input = new AnalysisInput(
            keyword: '',
            title: 'T',
            description: 'D',
            slug: 'p',
            plainText: 'Body.',
            html: '<p>Body.</p><img src="a.jpg" width="10" height="10">',
        );

        $this->assertSame(AnalysisStatus::Good, (new PageWeightCheck)->analyse($input)->status);
    }

    public function test_the_delta_reports_what_changed_not_the_running_total(): void
    {
        $first = SeoScan::query()->create(['url_count' => 3, 'issue_count' => 2]);
        $this->issue($first, 'missing-title', 'a');
        $this->issue($first, 'missing-description', 'b');

        $second = SeoScan::query()->create(['url_count' => 3, 'issue_count' => 2]);
        // 'a' persists, 'b' was fixed, 'c' is new.
        $this->issue($second, 'missing-title', 'a');
        $this->issue($second, 'canonical-conflict', 'c');

        $delta = ScanDelta::between($second, $first);

        $this->assertCount(1, $delta->newIssues);
        $this->assertSame('canonical-conflict', $delta->newIssues[0]->check);
        $this->assertSame(1, $delta->resolved);
        $this->assertSame(2, $delta->totalIssues);
        $this->assertFalse($delta->isFirstScan);
        $this->assertTrue($delta->isNoteworthy());
    }

    public function test_an_unchanged_scan_is_not_worth_interrupting_anyone_for(): void
    {
        $first = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 1]);
        $this->issue($first, 'missing-title', 'a');

        $second = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 1]);
        $this->issue($second, 'missing-title', 'a');

        $delta = ScanDelta::between($second, $first);

        $this->assertSame([], $delta->newIssues);
        $this->assertFalse($delta->isNoteworthy());
    }

    public function test_only_new_errors_are_noteworthy_not_warnings(): void
    {
        $first = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 0]);

        $second = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 1]);
        $this->issue($second, 'thin-content', 'a', Severity::Warning);

        $delta = ScanDelta::between($second, $first);

        $this->assertCount(1, $delta->newIssues);
        $this->assertSame([], $delta->newErrors());
        $this->assertFalse($delta->isNoteworthy());
    }

    public function test_a_first_scan_with_findings_is_always_worth_reporting(): void
    {
        $scan = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 1]);
        $this->issue($scan, 'thin-content', 'a', Severity::Warning);

        $delta = ScanDelta::between($scan, null);

        $this->assertTrue($delta->isFirstScan);
        $this->assertTrue($delta->isNoteworthy());
    }

    public function test_every_shipped_check_has_fix_instructions(): void
    {
        $checks = [
            'missing-title', 'missing-description', 'thin-content', 'duplicate-title',
            'duplicate-description', 'hreflang-reciprocity', 'canonical-conflict',
            'orphan-page', 'missing-image-alt', 'heavy-page', 'redirect-chain',
            'redirect-loop', 'sitemap-redirect', 'schema-invalid', 'robots-sitemap-conflict',
            'keyword-in-title', 'keyword-in-description', 'keyword-in-slug',
            'keyword-in-opening', 'keyword-in-heading', 'keyword-density',
            'content-length', 'title-length', 'description-length', 'heading-structure',
            'links', 'image-alt', 'paragraph-length', 'sentence-length', 'passive-voice',
            'transition-words', 'reading-ease', 'page-weight',
        ];

        foreach ($checks as $check) {
            $this->assertNotNull(FixInstructions::for($check), "No fix instructions for [{$check}].");
        }

        $this->assertNull(FixInstructions::for('not-a-real-check'));
    }

    public function test_keyword_coverage_finds_unoptimised_and_competing_pages(): void
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $repository = $this->app->make(SeoMetaRepository::class);

        $registry->register(new FakeSeoSubjectSource('pages', [
            'a' => $this->subject('a')->subject,
            'b' => $this->subject('b')->subject,
            'c' => $this->subject('c')->subject,
        ], modelClass: DocPage::class));

        $repository->upsert(DocPage::class, 'a', ['focus_keywords' => ['coffee beans']]);
        $repository->upsert(DocPage::class, 'b', ['focus_keywords' => ['Coffee Beans']]);
        // 'c' is left with no keyword at all.

        $report = (new KeywordCoverage($registry, $repository))->report();

        $this->assertSame(3, $report['total']);
        $this->assertSame(2, $report['covered']);
        $this->assertCount(1, $report['uncovered']);
        $this->assertSame('https://site.test/c', $report['uncovered'][0]['url']);

        // Case must not hide a collision.
        $this->assertArrayHasKey('coffee beans', $report['cannibalised']);
        $this->assertCount(2, $report['cannibalised']['coffee beans']);
    }
}
