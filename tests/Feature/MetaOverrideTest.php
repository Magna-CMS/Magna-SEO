<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Meta\TemplateEngine;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Tests\TestCase;

/**
 * Per-entity overrides must win over templates and defaults. Uses a booted app
 * so the Eloquent SeoMeta model (with its casts) can be constructed, but touches
 * no database.
 */
final class MetaOverrideTest extends TestCase
{
    private function subject(bool $indexable = true): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Page,
            url: 'https://example.test/1',
            title: 'Content Title',
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            excerpt: 'Content excerpt',
        );
    }

    private function resolver(): MetaResolver
    {
        return new MetaResolver(new TemplateEngine, new IndexabilityPolicy(isProduction: true), 155);
    }

    public function test_scalar_overrides_beat_templates_and_defaults(): void
    {
        $override = new SeoMeta([
            'title' => 'Override Title',
            'description' => 'Override description',
            'canonical_url' => 'https://canonical.test/x',
            'og_title' => 'Override OG',
            'twitter_card' => 'app',
        ]);

        $head = $this->resolver()->resolve($this->subject(), $override, new SeoSettings);

        $this->assertSame('Override Title', $head->title);
        $this->assertSame('Override description', $head->metaNames['description']);
        $this->assertSame('https://canonical.test/x', $head->links['canonical']);
        $this->assertSame('Override OG', $head->metaProperties['og:title']);
        $this->assertSame('app', $head->metaNames['twitter:card']);
    }

    public function test_a_dangerous_canonical_override_falls_back_to_the_safe_url(): void
    {
        $override = new SeoMeta(['canonical_url' => 'javascript:alert(1)']);

        $head = $this->resolver()->resolve($this->subject(), $override, new SeoSettings);

        // The javascript: scheme is rejected; the canonical falls back to the
        // subject's own (safe) URL and never emits the hostile value.
        $this->assertSame('https://example.test/1', $head->links['canonical']);
        $this->assertStringNotContainsString('javascript:', $head->links['canonical']);
    }

    public function test_a_safe_canonical_override_is_used(): void
    {
        $https = $this->resolver()->resolve($this->subject(), new SeoMeta(['canonical_url' => 'https://canonical.test/x']), new SeoSettings);
        $this->assertSame('https://canonical.test/x', $https->links['canonical']);

        $relative = $this->resolver()->resolve($this->subject(), new SeoMeta(['canonical_url' => '/root-relative']), new SeoSettings);
        $this->assertSame('/root-relative', $relative->links['canonical']);
    }

    public function test_override_can_force_noindex_with_advanced_directives(): void
    {
        $override = new SeoMeta([
            'robots_index' => false,
            'robots_follow' => false,
            'robots_advanced' => ['noarchive' => true, 'max-image-preview' => 'large'],
        ]);

        $robots = $this->resolver()->resolve($this->subject(), $override, new SeoSettings)->metaNames['robots'];

        $this->assertSame('noindex, nofollow, noarchive, max-image-preview:large', $robots);
    }

    public function test_override_cannot_reindex_a_non_indexable_subject(): void
    {
        $override = new SeoMeta(['robots_index' => true, 'robots_follow' => true]);

        $robots = $this->resolver()->resolve($this->subject(indexable: false), $override, new SeoSettings)->metaNames['robots'];

        $this->assertSame('noindex, follow', $robots);
    }
}
