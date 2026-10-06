<?php

declare(strict_types=1);

namespace Magna\Seo\Redirects;

use Illuminate\Support\Facades\DB;
use Magna\Seo\Models\SeoNotFound;

/**
 * Records 404s so an editor can see which dead URLs people and crawlers actually
 * hit, and turn the worthwhile ones into redirects.
 *
 * A 404 log is a denial-of-service surface if written naively: anyone can request
 * unlimited distinct URLs. Two limits keep it bounded — an optional sample rate
 * for very high-traffic sites, and a hard row cap enforced by evicting the least
 * recently seen paths. Counting per distinct path rather than per request means
 * the useful signal (this URL is hit often) survives both.
 */
final class NotFoundLogger
{
    /**
     * @param  float  $sampleRate  1.0 records every 404; 0.1 records roughly one in ten.
     * @param  int  $maxRows  Hard cap on distinct paths retained.
     * @param  list<string>  $ignorePrefixes  Paths never logged (admin, assets, probes).
     */
    public function __construct(
        private readonly float $sampleRate = 1.0,
        private readonly int $maxRows = 5000,
        private readonly array $ignorePrefixes = [],
    ) {}

    public function record(string $path, ?string $referrer = null): void
    {
        $path = RedirectStore::normaliseSource($path);

        if ($this->ignored($path) || ! $this->sampled()) {
            return;
        }

        $hash = hash('sha256', $path);
        $now = now();

        $existing = SeoNotFound::query()->where('path_hash', $hash)->first();

        if ($existing instanceof SeoNotFound) {
            // An atomic increment, so concurrent 404s on the same path do not lose
            // counts to a read-modify-write race.
            SeoNotFound::query()->whereKey($existing->getKey())->update([
                'hits' => DB::raw('hits + 1'),
                'last_seen_at' => $now,
                'last_referrer' => $this->cleanReferrer($referrer) ?? $existing->last_referrer,
                'updated_at' => $now,
            ]);

            return;
        }

        SeoNotFound::query()->create([
            'path' => $path,
            'path_hash' => $hash,
            'last_referrer' => $this->cleanReferrer($referrer),
            'hits' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        $this->prune();
    }

    /**
     * Drop the least recently seen paths once the cap is exceeded. Run only when
     * a new path is inserted, so the common case (a repeat 404) stays a single
     * update.
     */
    public function prune(): void
    {
        $count = SeoNotFound::query()->count();

        if ($count <= $this->maxRows) {
            return;
        }

        $ids = SeoNotFound::query()
            ->orderBy('last_seen_at')
            ->orderBy('id')
            ->limit($count - $this->maxRows)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            SeoNotFound::query()->whereIn('id', $ids)->delete();
        }
    }

    private function ignored(string $path): bool
    {
        foreach ($this->ignorePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sampled(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (random_int(1, 1000000) / 1000000) <= $this->sampleRate;
    }

    /**
     * Referrers are attacker-controlled text stored and later rendered in the
     * admin. Only http(s) URLs are kept, truncated to the column width.
     */
    private function cleanReferrer(?string $referrer): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $referrer = trim($referrer);
        $scheme = strtolower((string) parse_url($referrer, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($referrer, 0, 2048);
    }
}
