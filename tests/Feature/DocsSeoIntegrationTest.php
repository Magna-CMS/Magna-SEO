<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Docs\Seo\DocsSubjectSource;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\SeoHeadRenderer;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Testing\PluginTestCase;

/**
 * Docs is the first real content source wired into SEO. These cover the mapping,
 * the capability-detected registration, and that a docs page renders a correct
 * head through the shared SEO pipeline.
 */
final class DocsSeoIntegrationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    private function publish(string $slug, array $attributes = []): DocPage
    {
        return DocPage::create(array_merge([
            'title' => 'Getting Started',
            'slug' => $slug,
            'excerpt' => 'How to begin.',
            'content' => "# Hi\n\nSome body text.",
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ], $attributes));
    }

    public function test_it_maps_a_published_page_to_a_subject(): void
    {
        $this->publish('getting-started');

        $subject = app(DocsSubjectSource::class)->resolve('getting-started');

        $this->assertNotNull($subject);
        $this->assertSame('docs:getting-started', $subject->key);
        $this->assertSame(SubjectType::Doc, $subject->type);
        $this->assertStringContainsString('/docs/getting-started', $subject->url);
        $this->assertTrue($subject->indexable);
        $this->assertSame('Getting Started', $subject->title);
        $this->assertSame('How to begin.', $subject->excerpt);
        $this->assertNotSame([], $subject->breadcrumbs);
    }

    public function test_it_does_not_resolve_a_draft_page(): void
    {
        $this->publish('secret', ['status' => 'draft', 'is_published' => false, 'published_at' => null]);

        $this->assertNull(app(DocsSubjectSource::class)->resolve('secret'));
    }

    public function test_the_source_registers_itself_with_the_registry(): void
    {
        /** @var SeoSourceRegistry $registry */
        $registry = app(SeoSourceRegistry::class);

        $this->assertTrue($registry->has('docs'));
        $this->assertInstanceOf(DocsSubjectSource::class, $registry->get('docs'));
    }

    public function test_a_docs_page_renders_a_correct_head_through_the_seo_pipeline(): void
    {
        $this->publish('getting-started');
        $subject = app(DocsSubjectSource::class)->resolve('getting-started');
        $this->assertNotNull($subject);

        $html = app(SeoHeadRenderer::class)->render($subject);

        $this->assertStringContainsString('<title>Getting Started</title>', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('/docs/getting-started', $html);
        // Test env is non-production, so the site must not advertise as indexable —
        // this is the behaviour the old hardcoded "index, follow" got wrong.
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
    }
}
