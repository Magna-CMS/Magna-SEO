<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Sitemap\SitemapGenerator;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

/**
 * The S5 exit gate: a large source paginates, stays inside a fixed query budget,
 * and never publishes a URL an editor pushed out of the index.
 */
final class SitemapPaginationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        Cache::flush();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, SeoSubject>
     */
    private function subjects(array $ids): array
    {
        $subjects = [];

        foreach ($ids as $id) {
            $subjects[$id] = new SeoSubject(
                key: 'fake:'.$id,
                type: SubjectType::Page,
                url: 'https://example.com/p/'.$id,
                title: 'Page '.$id,
                locale: 'en',
                indexable: true,
                updatedAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
                modelId: $id,
            );
        }

        return $subjects;
    }

    private function register(FakeSeoSubjectSource $source): SitemapGenerator
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $registry->register($source);

        return new SitemapGenerator($registry, new SeoMetaRepository);
    }

    public function test_a_source_larger_than_a_page_is_split_and_listed_in_the_index(): void
    {
        config()->set('seo.sitemap.max_urls', 10);

        $ids = array_map(strval(...), range(1, 25));
        $generator = $this->register(new FakeSeoSubjectSource('bulk', $this->subjects($ids)));

        $index = $generator->index();

        $this->assertStringContainsString('sitemap-bulk.xml', $index);
        $this->assertStringContainsString('sitemap-bulk-2.xml', $index);
        $this->assertStringContainsString('sitemap-bulk-3.xml', $index);
        $this->assertStringNotContainsString('sitemap-bulk-4.xml', $index);

        $this->assertSame(10, substr_count((string) $generator->child('bulk', 1), '<loc>'));
        $this->assertSame(5, substr_count((string) $generator->child('bulk', 3), '<loc>'));
        $this->assertNull($generator->child('bulk', 4));
        $this->assertNull($generator->child('bulk', 0));
    }

    public function test_a_large_source_is_built_within_a_fixed_query_budget(): void
    {
        config()->set('seo.sitemap.max_urls', 5000);

        $ids = array_map(strval(...), range(1, 10000));
        // No model class: nothing to bulk-load, so building 10 000 URLs must not
        // touch the database at all.
        $generator = $this->register(new FakeSeoSubjectSource('big', $this->subjects($ids)));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $generator->index();

        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame(5000, substr_count((string) $generator->child('big', 1), '<loc>'));
        $this->assertSame(5000, substr_count((string) $generator->child('big', 2), '<loc>'));
    }

    public function test_override_lookups_cost_one_query_per_chunk_not_per_url(): void
    {
        $ids = array_map(strval(...), range(1, 40));
        $source = new FakeSeoSubjectSource('docs-backed', $this->subjects($ids), modelClass: DocPage::class);
        $generator = $this->register($source);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $generator->child('docs-backed');

        // 40 subjects stream in one chunk of 500, so one bulk read covers them all.
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_a_page_marked_noindex_by_an_editor_is_excluded(): void
    {
        $subjects = $this->subjects(['keep', 'hide']);
        $source = new FakeSeoSubjectSource('guarded', $subjects, modelClass: DocPage::class);
        $generator = $this->register($source);

        $this->app->make(SeoMetaRepository::class)->upsert(DocPage::class, 'hide', ['robots_index' => false]);

        $xml = (string) $generator->child('guarded');

        $this->assertStringContainsString('https://example.com/p/keep', $xml);
        $this->assertStringNotContainsString('https://example.com/p/hide', $xml);
    }

    public function test_purge_drops_every_cached_page_so_new_content_appears(): void
    {
        config()->set('seo.sitemap.max_urls', 10);

        $source = new FakeSeoSubjectSource('mutable', $this->subjects(['a']));
        $generator = $this->register($source);

        $this->assertStringContainsString('/p/a', (string) $generator->child('mutable'));

        $source->replace($this->subjects(['a', 'b']));

        // Still the cached build.
        $this->assertStringNotContainsString('/p/b', (string) $generator->child('mutable'));

        $generator->purge();

        $this->assertStringContainsString('/p/b', (string) $generator->child('mutable'));
    }

    public function test_sitemaps_are_gzipped_for_clients_that_ask(): void
    {
        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Encoding', 'gzip');
        $this->assertStringContainsString('<sitemapindex', (string) gzdecode($response->getContent()));
    }
}
