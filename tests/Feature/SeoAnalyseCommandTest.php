<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Docs\Models\DocPage;
use Magna\Testing\PluginTestCase;

final class SeoAnalyseCommandTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    public function test_it_reports_a_score_for_a_known_subject(): void
    {
        DocPage::create([
            'title' => 'The Complete Guide',
            'slug' => 'guide',
            'excerpt' => 'A thorough guide to the topic.',
            'content' => str_repeat('This guide explains everything you need to know. ', 40),
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->artisan('seo:analyse', ['source' => 'docs', 'id' => 'guide', '--keyword' => 'guide'])
            ->expectsOutputToContain('SEO score')
            ->assertSuccessful();
    }

    public function test_it_fails_for_an_unknown_subject(): void
    {
        $this->artisan('seo:analyse', ['source' => 'docs', 'id' => 'missing'])
            ->assertFailed();
    }
}
