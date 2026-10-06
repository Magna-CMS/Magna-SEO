<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Seo\Analysis\AnalysisCache;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

/**
 * Page scores are computed AND stored.
 *
 * The storing half was missing for this plugin's whole life. Every reader was
 * built — the content list's score columns, the SEO and readability badges in
 * the entry table, the "needs work" filter, the weak-publish warning — and
 * nothing but the core entry form's save ever wrote `analysis_cache`. On a site
 * whose content predated the plugin, or whose content lives in a plugin with its
 * own editor, every score read "—" permanently and the filter meant to surface
 * the worst pages matched nothing at all.
 */
final class AnalysisCacheTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    private function subject(string $id, string $title, string $body): SeoSubject
    {
        return new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://site.test/'.$id,
            title: $title,
            locale: 'en',
            indexable: true,
            updatedAt: new \DateTimeImmutable,
            plainText: $body,
            modelId: $id,
        );
    }

    public function test_a_refresh_stores_both_scores_against_the_model(): void
    {
        $subject = $this->subject('1', 'Coffee beans: a buying guide', str_repeat('Coffee beans are graded by roast. ', 60));

        $report = app(AnalysisCache::class)->refresh($subject, User::class);

        $this->assertNotNull($report);

        $meta = app(SeoMetaRepository::class)->for(User::class, '1');
        $cache = $meta?->analysis_cache;

        $this->assertIsArray($cache);
        $this->assertSame($report->seoScore, $cache['seo_score']);
        $this->assertSame($report->readabilityScore, $cache['readability_score']);

        // The weak-publish warning reads `score`, and the editor panel reads
        // `results`. A slimmer payload here would disable both without a word.
        $this->assertSame($report->score, $cache['score']);
        $this->assertNotEmpty($cache['results']);

        $this->assertNotNull($meta?->analysis_computed_at);
    }

    public function test_a_subject_with_no_model_is_skipped_rather_than_guessed_at(): void
    {
        $subject = new SeoSubject(
            key: 'fake:x',
            type: SubjectType::Page,
            url: 'https://site.test/x',
            title: 'No model',
            locale: 'en',
            indexable: true,
            updatedAt: new \DateTimeImmutable,
        );

        // Meta is keyed by model class and id. A non-Eloquent source has nowhere
        // to keep a score, and inventing a key would collide with a real one.
        $this->assertNull(app(AnalysisCache::class)->refresh($subject, User::class));
        $this->assertNull(app(AnalysisCache::class)->refresh($subject, null));
    }

    public function test_scanning_the_site_scores_every_page_it_walks(): void
    {
        // The backfill path: content that existed before the plugin did has no
        // score until something walks it, and the scan already walks everything.
        $registry = app(SeoSourceRegistry::class);

        $registry->register(new FakeSeoSubjectSource('fake', [
            '1' => $this->subject('1', 'First page about coffee', str_repeat('Coffee is roasted then ground. ', 40)),
            '2' => $this->subject('2', 'Second page about tea', str_repeat('Tea leaves are dried and steeped. ', 40)),
        ], modelClass: User::class));

        app(SiteScanner::class)->scan();

        foreach (['1', '2'] as $id) {
            $cache = app(SeoMetaRepository::class)->for(User::class, $id)?->analysis_cache;

            $this->assertIsArray($cache, "Page {$id} was scanned but never scored.");
            $this->assertIsInt($cache['seo_score']);
        }
    }

    public function test_a_backfill_covers_every_registered_source(): void
    {
        app(SeoSourceRegistry::class)->register(new FakeSeoSubjectSource('fake', [
            '1' => $this->subject('1', 'One', 'Short.'),
            '2' => $this->subject('2', 'Two', 'Also short.'),
        ], modelClass: User::class));

        $seen = 0;

        $count = app(AnalysisCache::class)->refreshAll(
            app(SeoSourceRegistry::class)->all(),
            function () use (&$seen): void {
                $seen++;
            },
        );

        $this->assertSame(2, $count);
        $this->assertSame(2, $seen);
    }

    public function test_a_page_with_no_body_is_left_unscored(): void
    {
        // Analysing an empty page returns a number assembled from checks that
        // cannot fail on emptiness. It stored 38/100, which reads as "analysed
        // and mediocre" rather than "not written yet" on every screen that
        // shows a score. The scan already reports thin content as the real
        // problem; a fabricated score would only disguise it.
        $empty = $this->subject('1', 'Started but not written', '');

        $this->assertNull(app(AnalysisCache::class)->refresh($empty, User::class));
        $this->assertNull(app(SeoMetaRepository::class)->for(User::class, '1')?->analysis_cache);
    }

    public function test_a_stored_score_is_cleared_when_a_page_is_emptied(): void
    {
        $cache = app(AnalysisCache::class);

        $cache->refresh($this->subject('1', 'Full', str_repeat('Real body text here. ', 40)), User::class);
        $this->assertIsArray(app(SeoMetaRepository::class)->for(User::class, '1')?->analysis_cache);

        // Emptying a page must not leave its old score standing as though it
        // still described the page.
        $cache->refresh($this->subject('1', 'Full', ''), User::class);
        $this->assertNull(app(SeoMetaRepository::class)->for(User::class, '1')?->analysis_cache);
    }
}
