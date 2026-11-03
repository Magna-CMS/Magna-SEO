<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadHtmlRenderer;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Meta\TemplateEngine;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

/**
 * The site icon and theme colour: emitted on every page when configured, and
 * absent — byte for byte as before — when not.
 */
final class SiteIdentityMetaTest extends TestCase
{
    private function resolver(): MetaResolver
    {
        return new MetaResolver(new TemplateEngine, new IndexabilityPolicy(true), 155);
    }

    private function subject(): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://site.test/p',
            title: 'Page',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-11-03T00:00:00+00:00'),
        );
    }

    public function test_a_site_icon_becomes_the_icon_and_apple_touch_icon_links(): void
    {
        $head = $this->resolver()->resolve($this->subject(), null, new SeoSettings, new SeoImage('https://site.test/storage/icon.png'));

        $this->assertSame('https://site.test/storage/icon.png', $head->links['icon']);
        $this->assertSame('https://site.test/storage/icon.png', $head->links['apple-touch-icon']);

        $html = (new HeadHtmlRenderer)->render($head);
        $this->assertSame(1, substr_count($html, '<link rel="icon"'));
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="https://site.test/storage/icon.png">', $html);
    }

    public function test_without_an_icon_no_icon_link_is_emitted(): void
    {
        $head = $this->resolver()->resolve($this->subject(), null, new SeoSettings);

        $this->assertArrayNotHasKey('icon', $head->links);
        $this->assertArrayNotHasKey('apple-touch-icon', $head->links);
        $this->assertArrayNotHasKey('theme-color', $head->metaNames);
    }

    public function test_an_unsafe_icon_url_is_refused(): void
    {
        $head = $this->resolver()->resolve($this->subject(), null, new SeoSettings, new SeoImage('javascript:alert(1)'));

        $this->assertArrayNotHasKey('icon', $head->links);
    }

    public function test_the_theme_colour_must_be_a_hex_value(): void
    {
        $settings = new SeoSettings;

        $settings->theme_color = '#0B1220';
        $this->assertSame('#0B1220', $this->resolver()->resolve($this->subject(), null, $settings)->metaNames['theme-color']);

        $settings->theme_color = 'red;"><script>';
        $this->assertArrayNotHasKey('theme-color', $this->resolver()->resolve($this->subject(), null, $settings)->metaNames);
    }
}
