<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Seo\Support\EntrySeoState;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;

final class EntrySeoStateTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    private function record(): DocPage
    {
        return DocPage::create([
            'title' => 'Page',
            'slug' => 'page',
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    private function state(): EntrySeoState
    {
        return $this->app->make(EntrySeoState::class);
    }

    public function test_load_defaults_to_a_blank_indexable_override(): void
    {
        $loaded = $this->state()->load($this->record());

        $this->assertNull($loaded['seo_title']);
        $this->assertTrue($loaded['seo_robots_index']);
        $this->assertTrue($loaded['seo_robots_follow']);
    }

    public function test_save_then_load_round_trips(): void
    {
        $page = $this->record();

        $this->state()->save($page, [
            'seo_title' => 'Custom Title',
            'seo_description' => 'Custom description.',
            'seo_robots_index' => false,
            'seo_focus_keyword' => 'coffee',
        ]);

        $loaded = $this->state()->load($page);
        $this->assertSame('Custom Title', $loaded['seo_title']);
        $this->assertSame('Custom description.', $loaded['seo_description']);
        $this->assertFalse($loaded['seo_robots_index']);
        $this->assertSame('coffee', $loaded['seo_focus_keyword']);
    }

    public function test_a_blank_panel_does_not_create_a_row(): void
    {
        $page = $this->record();

        $this->state()->save($page, ['seo_robots_index' => true, 'seo_robots_follow' => true]);

        $this->assertNull($this->app->make(SeoMetaRepository::class)->forModel($page));
    }

    public function test_saving_is_morph_scoped_to_the_record(): void
    {
        $one = $this->record();
        $two = DocPage::create([
            'title' => 'Two',
            'slug' => 'two',
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->state()->save($one, ['seo_title' => 'Only One']);

        $this->assertSame('Only One', $this->state()->load($one)['seo_title']);
        $this->assertNull($this->state()->load($two)['seo_title']);
    }
}
