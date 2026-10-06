<?php

declare(strict_types=1);

namespace Magna\Seo\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Settings\SeoSettings;
use Throwable;

/**
 * Core Web Vitals from the Chrome UX Report: what real visitors experienced,
 * rather than what a lab test simulates.
 *
 * This is the only feature in the plugin that needs a network call per URL, so
 * it is opt-in and cached hard — a full day, because field data is a rolling
 * 28-day average and does not move meaningfully faster than that.
 *
 * Two honest limits worth stating where a user can see them. CrUX only has data
 * for URLs with enough real traffic, so a new or quiet page legitimately returns
 * nothing — that is not a failure and must not be shown as a zero. And the
 * numbers describe the last four weeks, so a fix made yesterday will not appear
 * here for a while; the page-weight checks are what give immediate feedback.
 */
final class CoreWebVitals
{
    private const ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

    /** Google's own "good" thresholds. */
    private const THRESHOLDS = [
        'largest_contentful_paint' => ['good' => 2500, 'poor' => 4000, 'unit' => 'ms', 'label' => 'Largest Contentful Paint'],
        'interaction_to_next_paint' => ['good' => 200, 'poor' => 500, 'unit' => 'ms', 'label' => 'Interaction to Next Paint'],
        'cumulative_layout_shift' => ['good' => 0.1, 'poor' => 0.25, 'unit' => '', 'label' => 'Cumulative Layout Shift'],
    ];

    public function __construct(private readonly int $ttl = 86400) {}

    public function isConfigured(): bool
    {
        return SeoSettings::get()->crux_api_key !== '';
    }

    /**
     * Field metrics for one URL, or null when the integration is off or CrUX has
     * no data for it.
     *
     * @return array<string, array{value: float, rating: string, label: string, unit: string}>|null
     */
    public function forUrl(string $url): ?array
    {
        if (! $this->isConfigured() || trim($url) === '') {
            return null;
        }

        $key = 'seo:crux:'.hash('sha256', $url);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var array<string, array{value: float, rating: string, label: string, unit: string}> $cached */
            return $cached;
        }

        // A miss is cached as a marker, so a quiet page does not re-ask Google
        // on every dashboard load for data that will not exist tomorrow either.
        if ($cached === 'none') {
            return null;
        }

        $metrics = $this->fetch($url);

        Cache::put($key, $metrics ?? 'none', $this->ttl);

        return $metrics;
    }

    /**
     * @return array<string, array{value: float, rating: string, label: string, unit: string}>|null
     */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post(self::ENDPOINT.'?key='.urlencode(SeoSettings::get()->crux_api_key), [
                    'url' => $url,
                    'formFactor' => 'PHONE',
                ]);
        } catch (Throwable $e) {
            Log::warning('CrUX query failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        // 404 is the normal answer for a URL with too little traffic, not an
        // error worth logging as one.
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('CrUX query rejected.', ['status' => $response->status()]);

            return null;
        }

        $raw = $response->json('record.metrics');

        if (! is_array($raw)) {
            return null;
        }

        $metrics = [];

        foreach (self::THRESHOLDS as $metric => $config) {
            $percentile = $raw[$metric]['percentiles']['p75'] ?? null;

            if (! is_numeric($percentile)) {
                continue;
            }

            $value = (float) $percentile;

            $metrics[$metric] = [
                'value' => $value,
                'rating' => match (true) {
                    $value <= $config['good'] => 'good',
                    $value <= $config['poor'] => 'needs-improvement',
                    default => 'poor',
                },
                'label' => $config['label'],
                'unit' => $config['unit'],
            ];
        }

        return $metrics === [] ? null : $metrics;
    }
}
