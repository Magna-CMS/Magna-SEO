<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Seo\Import\SeoImporter;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;

final class SeoImporterTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    private function target(): DocPage
    {
        return DocPage::create([
            'title' => 'Imported Page',
            'slug' => 'imported',
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_it_writes_mapped_meta_onto_the_target(): void
    {
        $page = $this->target();

        $imported = $this->app->make(SeoImporter::class)->import([
            '_yoast_wpseo_title' => 'Old SEO Title',
            '_yoast_wpseo_metadesc' => 'Old description.',
            '_yoast_wpseo_meta-robots-noindex' => '1',
        ], $page);

        $this->assertTrue($imported);

        $meta = $this->app->make(SeoMetaRepository::class)->forModel($page);
        $this->assertNotNull($meta);
        $this->assertSame('Old SEO Title', $meta->title);
        $this->assertSame('Old description.', $meta->description);
        $this->assertFalse($meta->robots_index);
    }

    public function test_unsupported_meta_writes_nothing(): void
    {
        $page = $this->target();

        $imported = $this->app->make(SeoImporter::class)->import(['unknown_key' => 'x'], $page);

        $this->assertFalse($imported);
        $this->assertNull($this->app->make(SeoMetaRepository::class)->forModel($page));
    }
}
