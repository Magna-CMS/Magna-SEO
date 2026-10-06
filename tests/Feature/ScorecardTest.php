<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\MatchType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scorecard\Scorecard;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

final class ScorecardTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    private function passed(Scorecard $card, string $id): bool
    {
        foreach ($card->run()->results as $result) {
            if ($result->id === $id) {
                return $result->passed;
            }
        }

        $this->fail("No scorecard result for [{$id}].");
    }

    private function subject(string $url): SeoSubject
    {
        return new SeoSubject(
            key: 'k:'.$url,
            type: SubjectType::Page,
            url: $url,
            title: 'T',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-15T00:00:00+00:00'),
        );
    }

    public function test_a_healthy_site_passes(): void
    {
        DocPage::create([
            'title' => 'Guide',
            'slug' => 'guide',
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->assertTrue($this->app->make(Scorecard::class)->run()->passed);
    }

    public function test_duplicate_canonicals_fail_the_scorecard(): void
    {
        $this->app->make(SeoSourceRegistry::class)->register(new FakeSeoSubjectSource('dupes', [
            'a' => $this->subject('https://example.test/same'),
            'b' => $this->subject('https://example.test/same'),
        ]));

        $card = $this->app->make(Scorecard::class);

        $this->assertFalse($this->passed($card, 'canonical-unique'));
        $this->assertFalse($card->run()->passed);
    }

    public function test_an_indexable_url_that_redirects_fails(): void
    {
        $this->app->make(SeoSourceRegistry::class)->register(new FakeSeoSubjectSource('live', [
            'a' => $this->subject('https://example.test/moved'),
        ]));

        $card = $this->app->make(Scorecard::class);
        $this->assertTrue($this->passed($card, 'live-urls-200'));

        SeoRedirect::query()->create([
            'source_path' => '/moved',
            'target' => '/elsewhere',
            'match_type' => MatchType::Exact->value,
            'status_code' => 301,
            'preserve_query' => true,
            'is_active' => true,
        ]);

        $this->assertFalse($this->passed($card, 'live-urls-200'));
    }

    public function test_a_future_dated_lastmod_fails(): void
    {
        $subject = new SeoSubject(
            key: 'k:future',
            type: SubjectType::Page,
            url: 'https://example.test/future',
            title: 'T',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('+2 days'),
        );

        $this->app->make(SeoSourceRegistry::class)
            ->register(new FakeSeoSubjectSource('future', ['a' => $subject]));

        $this->assertFalse($this->passed($this->app->make(Scorecard::class), 'lastmod-from-content'));
    }

    public function test_an_image_without_dimensions_fails(): void
    {
        $withDimensions = $this->subject('https://example.test/ok')
            ->withImages([new SeoImage(url: 'https://cdn.test/a.jpg', width: 1200, height: 630)]);

        $registry = $this->app->make(SeoSourceRegistry::class);
        $registry->register(new FakeSeoSubjectSource('sized', ['a' => $withDimensions]));

        $card = $this->app->make(Scorecard::class);
        $this->assertTrue($this->passed($card, 'image-dimensions'));

        $registry->register(new FakeSeoSubjectSource('unsized', [
            'b' => $this->subject('https://example.test/bad')->withImages([new SeoImage(url: 'https://cdn.test/b.jpg')]),
        ]));

        $this->assertFalse($this->passed($card, 'image-dimensions'));
    }

    public function test_non_production_must_be_noindex(): void
    {
        $card = $this->app->make(Scorecard::class);
        $this->assertTrue($this->passed($card, 'nonprod-noindex')); // default: noindex on

        $settings = SeoSettings::get();
        $settings->noindex_non_production = false;
        $settings->save();

        $this->assertFalse($this->passed($card, 'nonprod-noindex'));
    }
}
