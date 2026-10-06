<?php

declare(strict_types=1);

namespace Magna\Seo\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Throwable;

/**
 * IndexNow submission: instant indexing at Bing/Yandex/Seznam and any engine that
 * shares the protocol. Needs no account or credential — only a self-generated key
 * hosted at /{key}.txt (served by this plugin) and echoed in each submission.
 *
 * Submissions POST the site's own URLs to a single, fixed, trusted endpoint, so
 * there is no user-controlled destination and no SSRF surface.
 */
final class IndexNow
{
    public function __construct(private readonly SeoSourceRegistry $registry) {}

    /**
     * The configured key, or '' when none has been generated yet.
     */
    public function key(): string
    {
        return SeoSettings::get()->indexnow_key;
    }

    /**
     * Return the key, generating and persisting one on first use.
     */
    public function ensureKey(): string
    {
        $settings = SeoSettings::get();
        if ($settings->indexnow_key === '') {
            $settings->indexnow_key = bin2hex(random_bytes(16));
            $settings->save();
        }

        return $settings->indexnow_key;
    }

    /**
     * Constant-time check that a requested key file matches the configured key,
     * so the endpoint cannot be used to probe for the key.
     */
    public function keyFileMatches(string $candidate): bool
    {
        $key = $this->key();

        return $key !== '' && hash_equals($key, $candidate);
    }

    /**
     * Submit every indexable URL across all sources. Returns how many were sent.
     */
    public function submitAll(): int
    {
        $urls = $this->collectUrls();
        if ($urls === []) {
            return 0;
        }

        return $this->submit($urls) ? count($urls) : 0;
    }

    /**
     * @param  list<string>  $urls
     */
    public function submit(array $urls): bool
    {
        if ($urls === []) {
            return false;
        }

        $key = $this->ensureKey();
        $host = (string) parse_url((string) url('/'), PHP_URL_HOST);

        try {
            $response = Http::timeout(10)->acceptJson()->post($this->endpoint(), [
                'host' => $host,
                'key' => $key,
                'keyLocation' => url($key.'.txt'),
                'urlList' => array_slice($urls, 0, 10000),
            ]);
        } catch (Throwable $e) {
            Log::warning('IndexNow submission failed.', ['exception' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }

    /**
     * @return list<string>
     */
    private function collectUrls(): array
    {
        $urls = [];
        foreach ($this->registry->all() as $handle => $source) {
            try {
                $source->chunk(function (array $batch) use (&$urls): void {
                    foreach ($batch as $subject) {
                        if ($subject->indexable && $subject->url !== '') {
                            $urls[] = $subject->url;
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::warning('IndexNow: a source failed and was skipped.', [
                    'source' => $handle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($urls));
    }

    private function endpoint(): string
    {
        $endpoint = config('seo.indexnow.endpoint');

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : 'https://api.indexnow.org/indexnow';
    }
}
