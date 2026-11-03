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
        $this->enablePlugin('magna-cms/docs');
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

    public function test_the_software_application_fields_round_trip_through_schema_overrides(): void
    {
        $page = $this->record();
        $repository = $this->app->make(SeoMetaRepository::class);

        // Something else already keeps data in schema_overrides; it must survive.
        $repository->upsert($page->getMorphClass(), (string) $page->getKey(), ['schema_overrides' => ['other' => ['kept' => true]]]);

        $this->state()->save($page, [
            'seo_app_name' => 'Acme',
            'seo_app_alternate_names' => ['Acme billing'],
            'seo_app_category' => 'FinanceApplication',
            'seo_app_operating_systems' => ['Android', 'Windows'],
            'seo_app_download_url' => '/download',
            'seo_app_offers' => "Free | 0 | inr\n499 | INR\n\n",
        ]);

        $stored = $repository->forModel($page)?->schema_overrides;
        $this->assertSame(['kept' => true], $stored['other'] ?? null);
        $this->assertSame([
            'name' => 'Acme',
            'alternate_names' => ['Acme billing'],
            'application_category' => 'FinanceApplication',
            'operating_systems' => ['Android', 'Windows'],
            'download_url' => '/download',
            'offers' => [
                ['name' => 'Free', 'price' => '0', 'currency' => 'INR'],
                ['price' => '499', 'currency' => 'INR'],
            ],
        ], $stored['software_application'] ?? null);

        $loaded = $this->state()->load($page);
        $this->assertSame('Acme', $loaded['seo_app_name']);
        $this->assertSame("Free | 0 | INR\n499 | INR", $loaded['seo_app_offers']);

        // Clearing the name removes the app, and only the app.
        $this->state()->save($page, ['seo_app_name' => '']);
        $this->assertSame(['other' => ['kept' => true]], $repository->forModel($page)?->schema_overrides);
    }
}
