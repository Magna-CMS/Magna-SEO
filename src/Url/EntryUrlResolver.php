<?php

declare(strict_types=1);

namespace Magna\Seo\Url;

use DateTimeImmutable;
use Throwable;

/**
 * Resolves the public URL of a content entry from its delivery payload alone.
 *
 * Magna's core does not own an entry-to-URL map — the frontend that renders an
 * entry does, and a headless install may have several. So the mapping lives in
 * configuration: one path pattern per content type, with `*` as the fallback.
 * A content type with no pattern (and no fallback) has no public URL, which is
 * also the signal that it is not publicly renderable and therefore must never
 * be indexed or listed in a sitemap.
 *
 * The class is pure: it reads the payload it is given and issues no queries, so
 * decorating a list of N entries stays query-free.
 */
final class EntryUrlResolver
{
    /**
     * @param  string  $baseUrl  Site origin, e.g. "https://example.com" (blank disables URL building).
     * @param  array<string, string>  $patterns  Content type handle => path pattern; `*` is the fallback.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $patterns,
    ) {}

    /**
     * The absolute URL for an entry, or '' when this type has no public URL or a
     * token the pattern needs is missing from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function forPayload(string $contentType, array $payload): string
    {
        $pattern = $this->patterns[$contentType] ?? $this->patterns['*'] ?? null;

        if ($pattern === null || $pattern === '' || $this->baseUrl === '') {
            return '';
        }

        $tokens = $this->tokens($contentType, $payload);
        $path = $pattern;

        foreach ($this->tokensIn($pattern) as $token) {
            $value = $tokens[$token] ?? null;

            if ($value === null || $value === '') {
                // A pattern whose data is missing yields no URL at all rather than
                // a half-built one that would 404 and pollute canonicals/sitemaps.
                return '';
            }

            $path = str_replace('{'.$token.'}', $value, $path);
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * True when this content type is publicly renderable at all.
     */
    public function hasPatternFor(string $contentType): bool
    {
        $pattern = $this->patterns[$contentType] ?? $this->patterns['*'] ?? null;

        return is_string($pattern) && $pattern !== '';
    }

    /**
     * Token names used by a pattern, e.g. "slug" and "year" for "/{year}/{slug}".
     *
     * @return list<string>
     */
    private function tokensIn(string $pattern): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $matches);

        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));

        return $names;
    }

    /**
     * Every token value is URL-encoded here, before substitution, so a hostile or
     * merely sloppy slug can neither traverse out of its path segment nor inject
     * a query string into the canonical URL.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function tokens(string $contentType, array $payload): array
    {
        $date = $this->publishDate($payload);

        $values = [
            'type' => $contentType,
            'slug' => $this->string($payload, 'slug'),
            'id' => $this->string($payload, 'id'),
            'locale' => $this->string($payload, 'locale'),
            'year' => $date?->format('Y') ?? '',
            'month' => $date?->format('m') ?? '',
            'day' => $date?->format('d') ?? '',
        ];

        return array_map(rawurlencode(...), $values);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishDate(array $payload): ?DateTimeImmutable
    {
        foreach (['published_at', 'created_at'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                try {
                    return new DateTimeImmutable($value);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
