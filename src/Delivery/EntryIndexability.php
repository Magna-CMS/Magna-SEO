<?php

declare(strict_types=1);

namespace Magna\Seo\Delivery;

use DateTimeImmutable;
use Magna\Seo\Meta\IndexabilityPolicy;
use Throwable;

/**
 * The source-level indexability verdict for a content entry: is this thing a
 * live, publicly reachable page right now?
 *
 * Deliberately separate from {@see IndexabilityPolicy}, which
 * layers environment and per-entity editor overrides on top. This class answers
 * only the content question, from the payload alone, with no queries.
 */
final class EntryIndexability
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  bool  $hasUrl  False when the content type has no public URL pattern.
     */
    public function isIndexable(array $payload, bool $hasUrl, ?DateTimeImmutable $now = null): bool
    {
        if (! $hasUrl) {
            // Not publicly renderable: no URL means nothing for a crawler to fetch.
            return false;
        }

        $status = $payload['status'] ?? null;

        if ($status !== 'published') {
            // draft, scheduled and archived are all non-public.
            return false;
        }

        $now ??= new DateTimeImmutable;

        $publishedAt = $this->date($payload, 'published_at');
        if ($publishedAt !== null && $publishedAt > $now) {
            // Marked published but dated forward — still embargoed.
            return false;
        }

        $unpublishAt = $this->date($payload, 'unpublish_at');
        if ($unpublishAt !== null && $unpublishAt <= $now) {
            // Its publication window has closed.
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function date(array $payload, string $key): ?DateTimeImmutable
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
