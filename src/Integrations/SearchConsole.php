<?php

declare(strict_types=1);

namespace Magna\Seo\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Settings\SeoSettings;
use Throwable;

/**
 * Google Search Console: how a URL is actually performing in search — clicks,
 * impressions, click-through rate and average position — surfaced next to the
 * page an editor is working on.
 *
 * Both hosts are fixed constants, and the only site-controlled value (the
 * property URL) is URL-encoded into Google's own path, so there is no
 * user-controlled destination and no SSRF surface.
 *
 * Credentials are stored encrypted (see SeoSettings). This uses an offline
 * refresh token rather than an interactive login: the CMS is not a browser, and
 * an editor should not have to re-authorise to see their own numbers.
 *
 * Every failure degrades to null. Search Console being unreachable must never
 * break an editor screen, so the caller shows "no data" and the reason is logged.
 */
final class SearchConsole
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_HOST = 'https://searchconsole.googleapis.com';

    private const TOKEN_CACHE_KEY = 'seo:gsc:access-token';

    public function __construct(private readonly int $metricsTtl = 21600) {}

    public function isConfigured(): bool
    {
        $settings = SeoSettings::get();

        return $settings->search_console_site !== ''
            && $settings->search_console_client_id !== ''
            && $settings->search_console_client_secret !== ''
            && $settings->search_console_refresh_token !== '';
    }

    /**
     * Performance for one URL over the trailing window, or null when the
     * integration is unconfigured or unavailable.
     *
     * @return array{clicks: int, impressions: int, ctr: float, position: float}|null
     */
    public function metricsFor(string $url, int $days = 28): ?array
    {
        if (! $this->isConfigured() || trim($url) === '') {
            return null;
        }

        $cacheKey = 'seo:gsc:url:'.hash('sha256', $url.'|'.$days);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            /** @var array{clicks: int, impressions: int, ctr: float, position: float} $cached */
            return $cached;
        }

        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        $site = rawurlencode(SeoSettings::get()->search_console_site);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->post(self::API_HOST."/webmasters/v3/sites/{$site}/searchAnalytics/query", [
                    'startDate' => now()->subDays($days)->toDateString(),
                    'endDate' => now()->toDateString(),
                    'dimensions' => ['page'],
                    'dimensionFilterGroups' => [[
                        'filters' => [[
                            'dimension' => 'page',
                            'operator' => 'equals',
                            'expression' => $url,
                        ]],
                    ]],
                    'rowLimit' => 1,
                ]);
        } catch (Throwable $e) {
            Log::warning('Search Console query failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Search Console query rejected.', ['status' => $response->status()]);

            return null;
        }

        $row = $response->json('rows.0');

        $metrics = [
            'clicks' => (int) (is_array($row) ? ($row['clicks'] ?? 0) : 0),
            'impressions' => (int) (is_array($row) ? ($row['impressions'] ?? 0) : 0),
            'ctr' => (float) (is_array($row) ? ($row['ctr'] ?? 0) : 0),
            'position' => (float) (is_array($row) ? ($row['position'] ?? 0) : 0),
        ];

        // Cached even when the row is empty: a page with no impressions yet would
        // otherwise re-query on every editor load.
        Cache::put($cacheKey, $metrics, $this->metricsTtl);

        return $metrics;
    }

    /**
     * Site-wide totals for the trailing window, plus the same window immediately
     * before it, so a change can be shown rather than a bare number. A number
     * with no direction tells a site owner nothing about whether to act.
     *
     * @return array{clicks: int, impressions: int, ctr: float, position: float, previous: array{clicks: int, impressions: int, ctr: float, position: float}}|null
     */
    public function siteTotals(int $days = 28): ?array
    {
        $current = $this->totalsBetween(now()->subDays($days), now(), $days);
        $previous = $this->totalsBetween(now()->subDays($days * 2), now()->subDays($days + 1), $days);

        if ($current === null) {
            return null;
        }

        return [...$current, 'previous' => $previous ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0]];
    }

    /**
     * Daily rows for the trend chart.
     *
     * @return list<array{date: string, clicks: int, impressions: int}>|null
     */
    public function dailySeries(int $days = 28): ?array
    {
        $rows = $this->query([
            'startDate' => now()->subDays($days)->toDateString(),
            'endDate' => now()->toDateString(),
            'dimensions' => ['date'],
            'rowLimit' => max($days, 1),
        ], 'daily:'.$days);

        if ($rows === null) {
            return null;
        }

        $series = [];
        foreach ($rows as $row) {
            $series[] = [
                'date' => (string) ($row['keys'][0] ?? ''),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * The queries the site actually appears for, with their movement since the
     * previous window.
     *
     * Note what is deliberately absent: search volume. Search Console reports
     * how this site performs, not how large a market is, and no free source
     * gives the latter. Impressions is the honest figure — it is real data about
     * this site rather than somebody's estimate of a market.
     *
     * @return list<array{query: string, clicks: int, impressions: int, position: float, change: float|null}>|null
     */
    public function topQueries(int $days = 28, int $limit = 20): ?array
    {
        $rows = $this->query([
            'startDate' => now()->subDays($days)->toDateString(),
            'endDate' => now()->toDateString(),
            'dimensions' => ['query'],
            'rowLimit' => $limit,
        ], 'queries:'.$days.':'.$limit);

        if ($rows === null) {
            return null;
        }

        $before = [];
        foreach ($this->query([
            'startDate' => now()->subDays($days * 2)->toDateString(),
            'endDate' => now()->subDays($days + 1)->toDateString(),
            'dimensions' => ['query'],
            'rowLimit' => 500,
        ], 'queries-previous:'.$days) ?? [] as $row) {
            $before[(string) ($row['keys'][0] ?? '')] = (float) ($row['position'] ?? 0);
        }

        $queries = [];
        foreach ($rows as $row) {
            $term = (string) ($row['keys'][0] ?? '');
            $position = (float) ($row['position'] ?? 0);
            $was = $before[$term] ?? null;

            $queries[] = [
                'query' => $term,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'position' => $position,
                // Positive means it moved up the page: position 8 to position 3
                // is an improvement of 5, even though the number went down.
                'change' => $was === null ? null : round($was - $position, 1),
            ];
        }

        return $queries;
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float, position: float}|null
     */
    private function totalsBetween(mixed $start, mixed $end, int $days): ?array
    {
        $rows = $this->query([
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'rowLimit' => 1,
        ], 'totals:'.$start->toDateString().':'.$days);

        if ($rows === null) {
            return null;
        }

        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ];
    }

    /**
     * One Search Analytics call, cached. Returns null when the integration is
     * unconfigured or unavailable — every caller degrades to "no data" rather
     * than failing a page.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>|null
     */
    private function query(array $body, string $cacheKey): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $key = 'seo:gsc:'.hash('sha256', $cacheKey);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var list<array<string, mixed>> $cached */
            return $cached;
        }

        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        $site = rawurlencode(SeoSettings::get()->search_console_site);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->post(self::API_HOST."/webmasters/v3/sites/{$site}/searchAnalytics/query", $body);
        } catch (Throwable $e) {
            Log::warning('Search Console query failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Search Console query rejected.', ['status' => $response->status()]);

            return null;
        }

        $rows = $response->json('rows');
        $rows = is_array($rows) ? array_values($rows) : [];

        /** @var list<array<string, mixed>> $rows */
        Cache::put($key, $rows, $this->metricsTtl);

        return $rows;
    }

    /**
     * Exchange the stored refresh token for an access token, cached until shortly
     * before it expires. A revoked token surfaces here as a failure, and every
     * caller degrades rather than throwing.
     */
    public function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $settings = SeoSettings::get();

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => $settings->search_console_client_id,
                'client_secret' => $settings->search_console_client_secret,
                'refresh_token' => $settings->search_console_refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (Throwable $e) {
            Log::warning('Search Console token refresh failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Search Console refused the refresh token.', ['status' => $response->status()]);

            return null;
        }

        $token = $response->json('access_token');
        $expires = (int) ($response->json('expires_in') ?? 3600);

        if (! is_string($token) || $token === '') {
            return null;
        }

        // Expire the cache a minute early so a token is never used at the moment
        // it lapses.
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expires - 60));

        return $token;
    }

    /**
     * Forget the cached access token — used when credentials change or are
     * revoked, so the next call re-authorises instead of retrying a dead token.
     */
    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }
}
