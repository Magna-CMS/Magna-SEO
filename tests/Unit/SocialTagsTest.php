<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Delivery\EntryPayloadMapper;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Meta\TemplateEngine;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Url\EntryUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * The parts of S3 that need no media table: the per-network image split, the
 * card-type precedence, and the body-image rung of the fallback chain.
 */
final class SocialTagsTest extends TestCase
{
    private function resolver(): MetaResolver
    {
        return new MetaResolver(new TemplateEngine, new IndexabilityPolicy(true));
    }

    /**
     * @param  list<SeoImage>  $images
     */
    private function subject(array $images = []): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://example.com/p',
            title: 'Hello',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            images: $images,
        );
    }

    public function test_a_twitter_specific_image_does_not_change_the_open_graph_one(): void
    {
        $subject = $this->subject([
            new SeoImage(url: 'https://cdn.test/og.jpg', width: 1200, height: 630),
            new SeoImage(url: 'https://cdn.test/tw.jpg', role: 'twitter'),
        ]);

        $head = $this->resolver()->resolve($subject, null, new SeoSettings);

        $this->assertSame('https://cdn.test/og.jpg', $head->metaProperties['og:image']);
        $this->assertSame('1200', $head->metaProperties['og:image:width']);
        $this->assertSame('https://cdn.test/tw.jpg', $head->metaNames['twitter:image']);
    }

    public function test_twitter_reuses_the_open_graph_image_when_it_has_none_of_its_own(): void
    {
        $subject = $this->subject([new SeoImage(url: 'https://cdn.test/og.jpg')]);

        $head = $this->resolver()->resolve($subject, null, new SeoSettings);

        $this->assertSame('https://cdn.test/og.jpg', $head->metaNames['twitter:image']);
    }

    public function test_card_type_precedence_override_then_setting_then_automatic(): void
    {
        $settings = new SeoSettings;
        $withImage = $this->subject([new SeoImage(url: 'https://cdn.test/og.jpg')]);

        $this->assertSame('summary_large_image', $this->resolver()->resolve($withImage, null, $settings)->metaNames['twitter:card']);
        $this->assertSame('summary', $this->resolver()->resolve($this->subject(), null, $settings)->metaNames['twitter:card']);

        $settings->twitter_card_type = 'summary';
        $this->assertSame('summary', $this->resolver()->resolve($withImage, null, $settings)->metaNames['twitter:card']);

        $override = new SeoMeta(['twitter_card' => 'player']);
        $this->assertSame('player', $this->resolver()->resolve($withImage, $override, $settings)->metaNames['twitter:card']);
    }

    public function test_an_invalid_card_type_falls_back_instead_of_being_emitted(): void
    {
        $settings = new SeoSettings;
        $settings->twitter_card_type = '"><script>';

        $head = $this->resolver()->resolve($this->subject(), null, $settings);

        $this->assertSame('summary', $head->metaNames['twitter:card']);
    }

    public function test_the_first_body_image_is_used_when_no_image_field_matched(): void
    {
        $mapper = new EntryPayloadMapper(
            titleFields: ['title'],
            excerptFields: [],
            bodyFields: ['body'],
            imageFields: ['og_image'],
            defaultLocale: 'en',
            urls: new EntryUrlResolver('https://example.com', ['*' => '/{type}/{slug}']),
        );

        $subject = $mapper->fromPayload('post', [
            'id' => '1',
            'slug' => 'p',
            'title' => 'T',
            'body' => '<p>Intro</p><img src="https://cdn.test/inline.jpg" alt="x"><img src="https://cdn.test/second.jpg">',
        ]);

        $this->assertNotNull($subject);
        $this->assertSame('https://cdn.test/inline.jpg', $subject->primaryImage()?->url);
    }

    public function test_a_data_uri_body_image_is_rejected(): void
    {
        $mapper = new EntryPayloadMapper(
            titleFields: ['title'],
            excerptFields: [],
            bodyFields: ['body'],
            imageFields: ['og_image'],
        );

        $subject = $mapper->fromPayload('post', [
            'id' => '1',
            'title' => 'T',
            'body' => '<img src="data:image/png;base64,AAAA">',
        ]);

        $this->assertNotNull($subject);
        $this->assertNull($subject->primaryImage());
    }
}
