<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Meta\TemplateEngine;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

final class MetaResolverTest extends TestCase
{
    /**
     * @param  list<SeoImage>  $images
     */
    private function subject(
        SubjectType $type = SubjectType::Page,
        string $url = '',
        bool $indexable = true,
        ?string $excerpt = 'Summary text',
        string $plainText = '',
        array $images = [],
        ?DateTimeImmutable $publishedAt = null,
    ): SeoSubject {
        return new SeoSubject(
            key: 'page:1',
            type: $type,
            url: $url,
            title: 'Hello',
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable('2026-08-14T12:00:00+00:00'),
            plainText: $plainText,
            excerpt: $excerpt,
            publishedAt: $publishedAt,
            images: $images,
        );
    }

    private function resolve(SeoSubject $subject, ?SeoSettings $settings = null, bool $isProduction = true): HeadPayload
    {
        $resolver = new MetaResolver(new TemplateEngine, new IndexabilityPolicy($isProduction), 155);

        return $resolver->resolve($subject, null, $settings ?? new SeoSettings);
    }

    public function test_title_drops_a_dangling_separator_when_sitename_is_empty(): void
    {
        $this->assertSame('Hello', $this->resolve($this->subject())->title);
    }

    public function test_title_includes_sitename_when_set(): void
    {
        $settings = new SeoSettings;
        $settings->site_name = 'Acme';

        $head = $this->resolve($this->subject(), $settings);

        $this->assertSame('Hello - Acme', $head->title);
        $this->assertSame('Acme', $head->metaProperties['og:site_name']);
    }

    public function test_description_falls_back_to_excerpt_then_body_then_nothing(): void
    {
        $this->assertSame('Summary text', $this->resolve($this->subject(excerpt: 'Summary text'))->metaNames['description']);
        $this->assertSame('Body only', $this->resolve($this->subject(excerpt: null, plainText: 'Body only'))->metaNames['description']);
        $this->assertArrayNotHasKey('description', $this->resolve($this->subject(excerpt: null, plainText: ''))->metaNames);
    }

    public function test_canonical_is_emitted_only_when_the_subject_has_a_url(): void
    {
        $this->assertArrayNotHasKey('canonical', $this->resolve($this->subject(url: ''))->links);

        $head = $this->resolve($this->subject(url: 'https://example.test/1'));
        $this->assertSame('https://example.test/1', $head->links['canonical']);
        $this->assertSame('https://example.test/1', $head->metaProperties['og:url']);
    }

    public function test_open_graph_type_tracks_the_subject_type(): void
    {
        $this->assertSame('website', $this->resolve($this->subject(SubjectType::Page))->metaProperties['og:type']);

        $head = $this->resolve($this->subject(SubjectType::Article, publishedAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00')));
        $this->assertSame('article', $head->metaProperties['og:type']);
        $this->assertSame('2026-08-01T00:00:00+00:00', $head->metaProperties['article:published_time']);
        $this->assertArrayHasKey('article:modified_time', $head->metaProperties);
    }

    public function test_images_drive_og_and_twitter_image_tags(): void
    {
        $withImage = $this->resolve($this->subject(images: [new SeoImage('https://cdn.test/a.jpg', width: 1200, height: 630, alt: 'Alt')]));
        $this->assertSame('https://cdn.test/a.jpg', $withImage->metaProperties['og:image']);
        $this->assertSame('1200', $withImage->metaProperties['og:image:width']);
        $this->assertSame('630', $withImage->metaProperties['og:image:height']);
        $this->assertSame('Alt', $withImage->metaProperties['og:image:alt']);
        $this->assertSame('summary_large_image', $withImage->metaNames['twitter:card']);
        $this->assertSame('https://cdn.test/a.jpg', $withImage->metaNames['twitter:image']);

        $withoutImage = $this->resolve($this->subject());
        $this->assertSame('summary', $withoutImage->metaNames['twitter:card']);
    }

    public function test_robots_is_index_follow_for_an_indexable_subject_in_production(): void
    {
        $this->assertSame('index, follow', $this->resolve($this->subject(indexable: true))->metaNames['robots']);
        $this->assertSame('noindex, follow', $this->resolve($this->subject(indexable: false))->metaNames['robots']);
    }

    public function test_hreflang_alternates_emit_only_for_a_translation_set(): void
    {
        $this->assertSame([], $this->resolve($this->subject())->alternates);

        $multi = new SeoSubject(
            key: 'p',
            type: SubjectType::Page,
            url: 'https://example.test/1',
            title: 'Hello',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            alternates: ['en' => 'https://example.test/1', 'fr' => 'https://example.test/fr/1'],
        );

        $head = $this->resolve($multi);

        // en, fr, and an x-default pointing at the first (default) alternate.
        $this->assertCount(3, $head->alternates);
        $this->assertSame(['hreflang' => 'en', 'href' => 'https://example.test/1'], $head->alternates[0]);
        $this->assertSame(['hreflang' => 'x-default', 'href' => 'https://example.test/1'], $head->alternates[2]);
    }

    public function test_search_engine_verification_tags_emit_when_configured(): void
    {
        $settings = new SeoSettings;
        $settings->google_site_verification = 'google-token';
        $settings->bing_site_verification = 'bing-token';

        $names = $this->resolve($this->subject(), $settings)->metaNames;

        $this->assertSame('google-token', $names['google-site-verification']);
        $this->assertSame('bing-token', $names['msvalidate.01']);
        $this->assertArrayNotHasKey('p:domain_verify', $names);
        $this->assertArrayNotHasKey('yandex-verification', $this->resolve($this->subject())->metaNames);
    }

    public function test_twitter_site_is_normalised_with_a_single_at(): void
    {
        $settings = new SeoSettings;
        $settings->twitter_site = 'acme';
        $this->assertSame('@acme', $this->resolve($this->subject(), $settings)->metaNames['twitter:site']);

        $settings->twitter_site = '@acme';
        $this->assertSame('@acme', $this->resolve($this->subject(), $settings)->metaNames['twitter:site']);
    }
}
