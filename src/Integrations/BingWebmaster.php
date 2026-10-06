<?php

declare(strict_types=1);

namespace Magna\Seo\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Settings\SeoSettings;
use Throwable;

/**
 * Bing Webmaster Tools URL submission.
 *
 * Mostly redundant with IndexNow, which Bing also honours and which needs no
 * account at all — but a site already using Bing Webmaster has a per-day quota
 * there worth spending, and submission through the account is what shows up in
 * its own reporting.
 *
 * The endpoint is a fixed constant and the API key travels as a query parameter
 * because that is what Bing's API requires; no site-supplied value reaches the
 * host. Failures are logged and swallowed, never surfaced to an editor.
 */
final class BingWebmaster
{
    private const ENDPOINT = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch';

    /** Bing's own per-request ceiling. */
    private const MAX_URLS = 500;

    public function isConfigured(): bool
    {
        return SeoSettings::get()->bing_webmaster_api_key !== '';
    }

    /**
     * @param  list<string>  $urls
     * @return bool True when Bing accepted the batch.
     */
    public function submit(array $urls): bool
    {
        $settings = SeoSettings::get();

        if ($settings->bing_webmaster_api_key === '' || $urls === []) {
            return false;
        }

        $siteUrl = rtrim((string) url('/'), '/').'/';

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post(self::ENDPOINT.'?apikey='.urlencode($settings->bing_webmaster_api_key), [
                    'siteUrl' => $siteUrl,
                    'urlList' => array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS),
                ]);
        } catch (Throwable $e) {
            Log::warning('Bing Webmaster submission failed.', ['exception' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Bing Webmaster rejected the submission.', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}
