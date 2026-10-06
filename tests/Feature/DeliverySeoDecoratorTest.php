<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Magna\Seo\Delivery\DeliverySeoDecorator;
use Magna\Testing\PluginTestCase;

final class DeliverySeoDecoratorTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    private function decorator(): DeliverySeoDecorator
    {
        return $this->app->make(DeliverySeoDecorator::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => '01HZZ',
            'type' => 'article',
            'status' => 'published',
            'locale' => 'en',
            'title' => 'Hello World',
            'updated_at' => '2026-08-14T12:00:00+00:00',
        ], $overrides);
    }

    public function test_it_injects_a_structured_seo_object_and_head_html(): void
    {
        $payload = $this->payload();
        $this->decorator()->decorate('article', '01HZZ', $payload);

        $this->assertArrayHasKey('seo', $payload);
        $seo = $payload['seo'];

        $this->assertSame('Hello World', $seo['title']);
        $this->assertSame('Hello World', $seo['properties']['og:title']);
        $this->assertArrayHasKey('robots', $seo['meta']);
        $this->assertStringContainsString('<title>Hello World</title>', $seo['head_html']);
    }

    public function test_it_emits_a_json_ld_graph(): void
    {
        $payload = $this->payload();
        $this->decorator()->decorate('article', '01HZZ', $payload);

        $jsonld = $payload['seo']['jsonld'];
        $this->assertCount(1, $jsonld);
        $this->assertSame('https://schema.org', $jsonld[0]['@context']);

        $types = array_column($jsonld[0]['@graph'], '@type');
        $this->assertContains('WebPage', $types);

        $this->assertStringContainsString('application/ld+json', $payload['seo']['head_html']);
    }

    public function test_non_production_environment_emits_noindex(): void
    {
        $payload = $this->payload();
        $this->decorator()->decorate('article', '01HZZ', $payload);

        // The test environment is not production, so the default guard applies.
        $this->assertSame('noindex, follow', $payload['seo']['meta']['robots']);
    }

    public function test_head_html_escapes_hostile_titles(): void
    {
        $payload = $this->payload(['title' => '"><script>alert(1)</script>']);
        $this->decorator()->decorate('article', '01HZZ', $payload);

        $this->assertStringNotContainsString('<script>alert(1)', $payload['seo']['head_html']);
        $this->assertStringContainsString('&lt;script&gt;', $payload['seo']['head_html']);
    }

    public function test_an_entry_without_a_title_is_left_untouched(): void
    {
        $payload = ['id' => '1', 'type' => 'article', 'status' => 'published', 'body' => 'x'];
        $this->decorator()->decorate('article', '1', $payload);

        $this->assertArrayNotHasKey('seo', $payload);
    }

    public function test_decorating_adds_no_queries_per_entry(): void
    {
        // Warm the once-per-request settings read.
        $warm = $this->payload();
        $this->decorator()->decorate('article', '01HZZ', $warm);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $next = $this->payload(['id' => '02', 'title' => 'Second']);
        $this->decorator()->decorate('article', '02', $next);

        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertArrayHasKey('seo', $next);
    }
}
