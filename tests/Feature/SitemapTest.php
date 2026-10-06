<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Testing\PluginTestCase;

final class SitemapTest extends PluginTestCase
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
            'title' => ucfirst($slug),
            'slug' => $slug,
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ], $attributes));
    }

    public function test_the_index_lists_a_child_sitemap_per_source(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<sitemapindex', false);
        $response->assertSee('sitemap-docs.xml', false);
    }

    public function test_a_child_lists_published_urls_with_lastmod_and_excludes_drafts(): void
    {
        $this->publish('alpha');
        $this->publish('beta', ['status' => 'draft', 'is_published' => false, 'published_at' => null]);

        $response = $this->get('/sitemap-docs.xml');

        $response->assertOk();
        $response->assertSee('<urlset', false);
        $response->assertSee('/docs/alpha', false);
        $response->assertSee('<lastmod>', false);
        $response->assertDontSee('/docs/beta', false);
    }

    public function test_an_unknown_source_is_not_found(): void
    {
        $this->get('/sitemap-nope.xml')->assertNotFound();
    }
}
