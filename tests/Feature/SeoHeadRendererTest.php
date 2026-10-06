<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\SeoHeadRenderer;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Testing\PluginTestCase;

final class SeoHeadRendererTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    private function subject(): SeoSubject
    {
        return new SeoSubject(
            key: 'doc:intro',
            type: SubjectType::Doc,
            url: 'https://site.test/docs/intro',
            title: 'Getting Started',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            excerpt: 'How to begin.',
        );
    }

    private function renderer(): SeoHeadRenderer
    {
        return $this->app->make(SeoHeadRenderer::class);
    }

    public function test_it_renders_a_complete_head_fragment(): void
    {
        $html = $this->renderer()->render($this->subject());

        $this->assertStringContainsString('<title>Getting Started</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="How to begin.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://site.test/docs/intro">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Getting Started">', $html);
        $this->assertStringContainsString('application/ld+json', $html);
    }

    public function test_a_per_entity_override_wins_on_the_render_path(): void
    {
        $override = new SeoMeta(['title' => 'Custom SEO Title', 'robots_index' => false]);

        $html = $this->renderer()->render($this->subject(), $override);

        $this->assertStringContainsString('<title>Custom SEO Title</title>', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
    }

    public function test_the_head_payload_carries_a_schema_graph(): void
    {
        $payload = $this->renderer()->head($this->subject());

        $this->assertNotEmpty($payload->jsonLd);
        $graph = $payload->jsonLd[0];
        $this->assertSame('https://schema.org', $graph['@context']);
        $this->assertContains('WebPage', array_column($graph['@graph'], '@type'));
    }
}
