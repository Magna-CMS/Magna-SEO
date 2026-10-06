<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Integrations\IndexNow;
use Magna\Testing\PluginTestCase;

final class IndexNowTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
    }

    private function publish(string $slug): void
    {
        DocPage::create([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_it_submits_indexable_urls_with_the_key(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
        $this->publish('guide');

        $count = $this->app->make(IndexNow::class)->submitAll();

        $this->assertSame(1, $count);
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'indexnow.org')
                && is_string($request['key']) && $request['key'] !== ''
                && in_array(url('docs/guide'), $request['urlList'], true);
        });
    }

    public function test_a_failed_submission_reports_zero(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 500)]);
        $this->publish('guide');

        $this->assertSame(0, $this->app->make(IndexNow::class)->submitAll());
    }

    public function test_the_key_file_is_served_only_for_the_configured_key(): void
    {
        $key = $this->app->make(IndexNow::class)->ensureKey();

        $this->get('/'.$key.'.txt')
            ->assertOk()
            ->assertSee($key, false)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->get('/deadbeefdeadbeefdeadbeefdeadbeef.txt')->assertNotFound();
    }
}
