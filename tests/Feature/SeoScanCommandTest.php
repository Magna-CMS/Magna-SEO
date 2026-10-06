<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Testing\PluginTestCase;

final class SeoScanCommandTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');

        // The repo ships a static public/robots.txt; point the shadowing check at a
        // path that does not exist so these fixtures test content, not the tree.
        config()->set('seo.robots.static_path', __DIR__.'/no-robots.txt');
    }

    private function publish(string $slug, string $title): void
    {
        DocPage::create([
            'title' => $title,
            'slug' => $slug,
            'content' => str_repeat('lorem ipsum dolor sit ', 40),
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_it_reports_a_summary(): void
    {
        $this->publish('a', 'Alpha');

        $this->artisan('seo:scan')
            ->expectsOutputToContain('Scanned')
            ->assertSuccessful();
    }

    public function test_strict_fails_when_issues_are_found(): void
    {
        $this->publish('a', 'Shared');
        $this->publish('b', 'Shared'); // duplicate title -> an issue

        $this->artisan('seo:scan', ['--strict' => true])->assertFailed();
    }

    public function test_strict_passes_on_a_clean_site(): void
    {
        $this->publish('a', 'Alpha');
        $this->publish('b', 'Beta');

        $this->artisan('seo:scan', ['--strict' => true])->assertSuccessful();
    }
}
