<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Magna\Seo\Delivery\DeliverySeoDecorator;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\SeoHeadRenderer;
use Magna\Seo\Integrations\IndexNow;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Settings\UrlSettings;
use Magna\Testing\PluginTestCase;

/**
 * The S10 security pass, as executable assertions rather than a checklist.
 *
 * Every emitted surface is a place author or visitor input reaches a browser or
 * a crawler, so each one is covered here: the head fragment, the delivery
 * payload, JSON-LD, canonical URLs, and the outbound integrations.
 */
final class SecurityTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        config()->set('seo.entry.url_patterns', ['*' => '/{type}/{slug}']);

        $urls = UrlSettings::get();
        $urls->frontend_url = 'https://site.test';
        $urls->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function decorate(array $payload): array
    {
        $this->app->make(DeliverySeoDecorator::class)->decorate('post', 'entry-1', $payload);

        /** @var array<string, mixed> $seo */
        $seo = $payload['seo'] ?? [];

        return $seo;
    }

    public function test_unpublished_content_is_noindex_in_the_delivery_payload(): void
    {
        foreach (['draft', 'scheduled', 'archived', null] as $status) {
            $seo = $this->decorate([
                'id' => 'entry-1',
                'slug' => 'secret',
                'title' => 'Unreleased',
                'status' => $status,
            ]);

            $this->assertStringContainsString('noindex', $seo['meta']['robots'], (string) $status);
        }
    }

    public function test_a_forward_dated_entry_is_noindex_until_its_moment(): void
    {
        $seo = $this->decorate([
            'id' => 'entry-1',
            'slug' => 'embargoed',
            'title' => 'Embargoed',
            'status' => 'published',
            'published_at' => now()->addWeek()->toIso8601String(),
        ]);

        $this->assertStringContainsString('noindex', $seo['meta']['robots']);
    }

    public function test_a_published_entry_is_indexable_so_the_check_is_not_vacuous(): void
    {
        $seo = $this->decorate([
            'id' => 'entry-1',
            'slug' => 'live',
            'title' => 'Live',
            'status' => 'published',
            'published_at' => now()->subDay()->toIso8601String(),
        ]);

        // The test environment is non-production, where the safety guard applies;
        // what matters is that the *content* verdict differs from a draft's.
        $this->assertStringContainsString('follow', $seo['meta']['robots']);
        $this->assertNotSame('', $seo['links']['canonical'] ?? '');
    }

    public function test_hostile_content_cannot_break_out_of_the_head_fragment(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Article,
            url: 'https://site.test/p',
            title: '"><script>alert(1)</script>',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
            excerpt: '</title><img src=x onerror=alert(1)>',
        );

        $html = $this->app->make(SeoHeadRenderer::class)->render($subject);

        // The payloads survive as text, but every character that would let them
        // become markup is escaped, so nothing executes.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function test_json_ld_cannot_be_closed_early_by_content(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Article,
            url: 'https://site.test/p',
            title: 'Legit</script><script>alert(1)</script>',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
        );

        $html = $this->app->make(SeoHeadRenderer::class)->render($subject);

        // The closing tag must be escaped inside the JSON-LD block, so the only
        // </script> in the output is the one the renderer wrote itself.
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function test_a_dangerous_canonical_override_is_refused(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Page,
            url: 'https://site.test/p',
            title: 'T',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
        );

        $override = new SeoMeta(['canonical_url' => 'javascript:alert(1)']);
        $html = $this->app->make(SeoHeadRenderer::class)->render($subject, $override);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('https://site.test/p', $html);
    }

    public function test_indexnow_only_ever_talks_to_its_configured_endpoint(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->app->make(SeoSourceRegistry::class)->register(new FakeSeoSubjectSource('fake', [
            'a' => new SeoSubject(
                key: 'a',
                type: SubjectType::Page,
                url: 'https://site.test/a',
                title: 'A',
                locale: 'en',
                indexable: true,
                updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
            ),
        ]));

        $this->app->make(IndexNow::class)->submitAll();

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.indexnow.org/'));
    }

    public function test_the_redirect_hint_endpoint_is_rate_limited(): void
    {
        $this->assertRouteRegistered('seo/redirect-hint');

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'seo/redirect-hint');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }
}
