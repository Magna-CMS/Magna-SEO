<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Dashboard\ContentOverview;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

/**
 * Phase 3: every indexable page in one filterable list, with the two fields most
 * often missing editable in place.
 */
final class ContentOverviewTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    private function subject(string $id, bool $indexable = true): SeoSubject
    {
        return new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://site.test/'.$id,
            title: ucfirst($id).' page',
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
            modelId: $id,
        );
    }

    private function overview(): ContentOverview
    {
        $registry = $this->app->make(SeoSourceRegistry::class);

        $registry->register(new FakeSeoSubjectSource('pages', [
            'alpha' => $this->subject('alpha'),
            'beta' => $this->subject('beta'),
            'gamma' => $this->subject('gamma', indexable: false),
        ], modelClass: DocPage::class));

        return new ContentOverview($registry, $this->app->make(SeoMetaRepository::class));
    }

    private function repository(): SeoMetaRepository
    {
        return $this->app->make(SeoMetaRepository::class);
    }

    public function test_it_lists_every_page_with_its_cached_scores(): void
    {
        $this->repository()->upsert(DocPage::class, 'alpha', [
            'description' => 'Alpha description.',
            'analysis_cache' => ['seo_score' => 82, 'readability_score' => 55],
        ]);

        $rows = $this->overview()->rows();

        $alpha = collect($rows)->firstWhere('modelId', 'alpha');

        $this->assertSame(82, $alpha['seoScore']);
        $this->assertSame(55, $alpha['readabilityScore']);
        $this->assertSame('Alpha description.', $alpha['description']);
        $this->assertSame(DocPage::class, $alpha['modelType']);
    }

    public function test_a_page_never_analysed_reports_no_score_rather_than_zero(): void
    {
        $rows = $this->overview()->rows();
        $beta = collect($rows)->firstWhere('modelId', 'beta');

        // Zero would read as "scored badly"; null reads as "not measured".
        $this->assertNull($beta['seoScore']);
    }

    public function test_the_worst_pages_sort_first_because_this_list_is_a_work_queue(): void
    {
        $this->repository()->upsert(DocPage::class, 'alpha', ['analysis_cache' => ['seo_score' => 90, 'readability_score' => 90]]);
        $this->repository()->upsert(DocPage::class, 'beta', ['analysis_cache' => ['seo_score' => 20, 'readability_score' => 40]]);

        $rows = array_values(array_filter($this->overview()->rows(), fn ($row) => $row['seoScore'] !== null));

        $this->assertSame(20, $rows[0]['seoScore']);
        $this->assertSame(90, $rows[1]['seoScore']);
    }

    public function test_filters_narrow_the_list_to_one_kind_of_problem(): void
    {
        $overview = $this->overview();

        $this->repository()->upsert(DocPage::class, 'alpha', [
            'description' => 'Has one.',
            'focus_keywords' => ['alpha'],
            'analysis_cache' => ['seo_score' => 30, 'readability_score' => 60],
        ]);

        $this->assertCount(1, $overview->rows(ContentOverview::FILTER_NEEDS_WORK));
        $this->assertCount(2, $overview->rows(ContentOverview::FILTER_NO_DESCRIPTION));
        $this->assertCount(2, $overview->rows(ContentOverview::FILTER_NO_KEYWORD));
        $this->assertCount(1, $overview->rows(ContentOverview::FILTER_NOINDEX));
        $this->assertCount(3, $overview->rows(ContentOverview::FILTER_ALL));
    }

    public function test_an_editor_noindex_shows_even_when_the_content_is_published(): void
    {
        $this->repository()->upsert(DocPage::class, 'alpha', ['robots_index' => false]);

        $noindex = $this->overview()->rows(ContentOverview::FILTER_NOINDEX);
        $keys = array_column($noindex, 'modelId');

        $this->assertContains('alpha', $keys, 'an override marking it noindex counts');
        $this->assertContains('gamma', $keys, 'so does content that is not published');
    }

    public function test_search_matches_titles_and_urls(): void
    {
        $overview = $this->overview();

        $this->assertCount(1, $overview->rows(ContentOverview::FILTER_ALL, 'beta'));
        $this->assertCount(0, $overview->rows(ContentOverview::FILTER_ALL, 'nothing-like-this'));
    }

    public function test_saving_from_the_list_writes_the_override(): void
    {
        $overview = $this->overview();

        $overview->save(DocPage::class, 'beta', 'A better title', 'A written description.');

        $meta = $this->repository()->for(DocPage::class, 'beta');

        $this->assertSame('A better title', $meta?->title);
        $this->assertSame('A written description.', $meta?->description);
    }

    public function test_clearing_a_field_stores_null_rather_than_an_empty_string(): void
    {
        $overview = $this->overview();

        $overview->save(DocPage::class, 'beta', 'Title', 'Description');
        $overview->save(DocPage::class, 'beta', '   ', '');

        $meta = $this->repository()->for(DocPage::class, 'beta');

        $this->assertNull($meta?->title);
        $this->assertNull($meta?->description);
    }
}
