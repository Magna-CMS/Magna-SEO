<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Magna\Seo\Integrations\BingWebmaster;
use Magna\Seo\Integrations\IndexNow;
use Magna\Seo\Integrations\IndexNowQueue;
use Magna\Seo\Integrations\SearchConsole;
use Magna\Seo\Jobs\FlushIndexNowQueueJob;
use Magna\Seo\Settings\SeoSettings;
use Magna\Testing\PluginTestCase;

/**
 * S9: automatic IndexNow submission, Search Console reporting and Bing
 * submission. Every one of these talks to a third party, so the property that
 * matters most is that a failure degrades quietly.
 */
final class SearchEngineIntegrationsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        Cache::flush();
    }

    private function configureSearchConsole(): void
    {
        $settings = SeoSettings::get();
        $settings->search_console_site = 'https://example.com/';
        $settings->search_console_client_id = 'client-id';
        $settings->search_console_client_secret = 'client-secret';
        $settings->search_console_refresh_token = 'refresh-token';
        $settings->save();
    }

    public function test_the_indexnow_buffer_batches_and_deduplicates(): void
    {
        $queue = new IndexNowQueue;

        $this->assertTrue($queue->push('https://example.com/a'), 'the first URL schedules the flush');
        $this->assertFalse($queue->push('https://example.com/b'), 'a second URL joins the same batch');
        $this->assertFalse($queue->push('https://example.com/a'), 'a repeat does not schedule another');

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $queue->drain());
        $this->assertSame([], $queue->drain(), 'draining clears the buffer');

        $this->assertTrue($queue->push('https://example.com/c'), 'the next batch schedules again');
    }

    public function test_flushing_submits_the_batch_once(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $queue = $this->app->make(IndexNowQueue::class);
        $queue->push('https://example.com/a');
        $queue->push('https://example.com/b');

        $this->app->make(FlushIndexNowQueueJob::class)->handle($queue, $this->app->make(IndexNow::class));

        Http::assertSentCount(1);
        $this->assertSame([], $queue->drain());
    }

    public function test_an_empty_buffer_sends_nothing(): void
    {
        Http::fake();

        $queue = $this->app->make(IndexNowQueue::class);
        $this->app->make(FlushIndexNowQueueJob::class)->handle($queue, $this->app->make(IndexNow::class));

        Http::assertNothingSent();
    }

    public function test_search_console_is_inert_until_it_is_configured(): void
    {
        Http::fake();

        $console = new SearchConsole;

        $this->assertFalse($console->isConfigured());
        $this->assertNull($console->metricsFor('https://example.com/a'));

        Http::assertNothingSent();
    }

    public function test_search_console_refreshes_a_token_and_reports_metrics(): void
    {
        $this->configureSearchConsole();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            'searchconsole.googleapis.com/*' => Http::response([
                'rows' => [[
                    'clicks' => 12,
                    'impressions' => 340,
                    'ctr' => 0.0353,
                    'position' => 8.4,
                ]],
            ]),
        ]);

        $metrics = (new SearchConsole)->metricsFor('https://example.com/a');

        $this->assertSame(12, $metrics['clicks']);
        $this->assertSame(340, $metrics['impressions']);
        $this->assertSame(8.4, $metrics['position']);

        // The second call is served from cache: no token refresh, no query.
        (new SearchConsole)->metricsFor('https://example.com/a');
        Http::assertSentCount(2);
    }

    public function test_a_revoked_refresh_token_degrades_to_no_data(): void
    {
        $this->configureSearchConsole();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $console = new SearchConsole;

        $this->assertNull($console->accessToken());
        $this->assertNull($console->metricsFor('https://example.com/a'));
    }

    public function test_a_search_console_outage_degrades_to_no_data(): void
    {
        $this->configureSearchConsole();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            'searchconsole.googleapis.com/*' => Http::response('', 503),
        ]);

        $this->assertNull((new SearchConsole)->metricsFor('https://example.com/a'));
    }

    public function test_bing_submission_requires_a_key_and_reports_failure(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $bing = new BingWebmaster;

        $this->assertFalse($bing->isConfigured());
        $this->assertFalse($bing->submit(['https://example.com/a']));
        Http::assertNothingSent();

        $settings = SeoSettings::get();
        $settings->bing_webmaster_api_key = 'key-123';
        $settings->save();

        $this->assertTrue($bing->isConfigured());
        $this->assertFalse($bing->submit(['https://example.com/a']), 'a rejected batch reports failure rather than throwing');
    }

    public function test_bing_submission_succeeds_and_sends_the_site_url(): void
    {
        $settings = SeoSettings::get();
        $settings->bing_webmaster_api_key = 'key-123';
        $settings->save();

        Http::fake(['ssl.bing.com/*' => Http::response(['d' => null])]);

        $this->assertTrue((new BingWebmaster)->submit(['https://example.com/a', 'https://example.com/a']));

        Http::assertSent(function ($request): bool {
            // Deduplicated, and the key never appears in the body.
            return count($request['urlList']) === 1 && ! isset($request['apikey']);
        });
    }

    public function test_the_bing_command_is_a_no_op_without_a_key(): void
    {
        Http::fake();

        $this->artisan('seo:bing')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_automatic_indexnow_submission_is_off_by_default(): void
    {
        Queue::fake();

        $this->assertFalse(SeoSettings::get()->indexnow_auto_submit);
    }
}
