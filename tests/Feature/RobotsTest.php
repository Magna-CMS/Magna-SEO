<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Seo\Settings\SeoSettings;
use Magna\Testing\PluginTestCase;

final class RobotsTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    public function test_non_production_disallows_everything(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Disallow: /', false);
        $response->assertDontSee('Sitemap:', false);
    }

    public function test_production_allows_and_points_at_the_sitemap(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSee('Allow: /', false);
        $response->assertSee('Sitemap:', false);
        $response->assertSee('/sitemap.xml', false);
    }

    public function test_ai_crawlers_are_allowed_by_default_and_llms_txt_is_advertised(): void
    {
        $this->app['env'] = 'production';

        $body = (string) $this->get('/robots.txt')->getContent();

        // Allowed is the default, and an allowed agent gets no block of its own —
        // the wildcard above already permits it.
        $this->assertStringNotContainsString('GPTBot', $body);
        $this->assertStringNotContainsString('OAI-SearchBot', $body);
        $this->assertStringContainsString('# llms.txt: ', $body);
    }

    public function test_each_ai_purpose_is_blocked_independently(): void
    {
        $this->app['env'] = 'production';

        $settings = SeoSettings::get();
        $settings->allow_ai_training = false;
        $settings->save();

        $body = (string) $this->get('/robots.txt')->getContent();

        // Training blocked…
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /", $body);
        $this->assertStringContainsString('ClaudeBot', $body);
        $this->assertStringContainsString('CCBot', $body);
        // …while AI search and live user fetches stay allowed, so the site is
        // still citable in assistant answers.
        $this->assertStringNotContainsString('OAI-SearchBot', $body);
        $this->assertStringNotContainsString('ChatGPT-User', $body);
    }

    public function test_blocking_ai_search_is_a_separate_decision(): void
    {
        $this->app['env'] = 'production';

        $settings = SeoSettings::get();
        $settings->allow_ai_search = false;
        $settings->allow_ai_user_fetch = false;
        $settings->save();

        $body = (string) $this->get('/robots.txt')->getContent();

        $this->assertStringContainsString('OAI-SearchBot', $body);
        $this->assertStringContainsString('PerplexityBot', $body);
        $this->assertStringContainsString('Claude-User', $body);
        $this->assertStringNotContainsString('GPTBot', $body);
    }

    public function test_no_llms_pointer_when_the_file_is_not_published(): void
    {
        $this->app['env'] = 'production';

        $settings = SeoSettings::get();
        $settings->llms_txt_enabled = false;
        $settings->save();

        $this->assertStringNotContainsString('llms.txt', (string) $this->get('/robots.txt')->getContent());
    }

    public function test_non_production_never_emits_ai_rules(): void
    {
        $settings = SeoSettings::get();
        $settings->allow_ai_training = false;
        $settings->save();

        $body = (string) $this->get('/robots.txt')->getContent();

        // Everything is already disallowed; per-agent rules would only add noise.
        $this->assertStringNotContainsString('GPTBot', $body);
        $this->assertSame("User-agent: *\nDisallow: /\n", $body);
    }
}
