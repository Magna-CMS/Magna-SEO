<?php

declare(strict_types=1);

namespace Magna\Seo\Redirects;

use Illuminate\Support\Facades\DB;
use Magna\Seo\Models\SeoRedirect;

/**
 * Matches a request path against the redirect rules and resolves it to a final
 * destination.
 *
 * Three behaviours here exist because getting them wrong is expensive in search
 * results, not merely inconvenient:
 *
 * - **Chains are collapsed.** If A → B and B → C, a visitor asking for A is sent
 *   straight to C in one hop. Crawlers discount link equity across hops and give
 *   up entirely after a handful.
 * - **Loops are broken, not followed.** A → B → A resolves to the first hop and
 *   stops, so a mistake in the rules costs one wasted redirect instead of an
 *   infinite one.
 * - **Rules are read once per request.** Redirect checks run on every 404, so the
 *   rule set is loaded lazily and memoised rather than re-queried per hop.
 */
final class RedirectStore
{
    /** @var list<SeoRedirect>|null */
    private ?array $regexRules = null;

    /** @var array<string, SeoRedirect|null> */
    private array $exactCache = [];

    public function __construct(private readonly int $maxHops = 5) {}

    /**
     * Resolve a path (plus its raw query string) to a redirect, or null when no
     * rule applies.
     */
    public function match(string $path, string $query = ''): ?RedirectMatch
    {
        $path = self::normalisePath($path);
        $rule = $this->find($path, $query);

        if ($rule === null) {
            return null;
        }

        $first = $rule;
        $hops = 1;
        $target = $this->targetFor($rule, $path, $query);
        $visited = [$path => true];

        // Collapse the chain: keep resolving while the destination is itself a
        // redirect source, stopping at a loop or the hop ceiling.
        while ($target !== null && $hops < $this->maxHops) {
            $next = $this->splitTarget($target);

            if ($next === null || isset($visited[$next[0]])) {
                break;
            }

            $visited[$next[0]] = true;
            $chained = $this->find($next[0], $next[1]);

            if ($chained === null) {
                break;
            }

            $rule = $chained;
            $hops++;
            $target = $this->targetFor($chained, $next[0], $next[1]);

            if ($chained->status_code === 410) {
                break;
            }
        }

        return new RedirectMatch(
            target: $rule->status_code === 410 ? null : $target,
            status: $rule->status_code,
            ruleId: $first->id,
            hops: $hops,
        );
    }

    /**
     * Record that a rule fired. Kept out of {@see match()} so a read-only caller
     * (the headless redirect-hint endpoint, the scan's chain check) does not
     * inflate the counters.
     */
    public function recordHit(int $ruleId): void
    {
        SeoRedirect::query()->whereKey($ruleId)->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]);
    }

    /**
     * Paths are compared without origin, query or fragment, with exactly one
     * leading slash, no trailing slash, and no duplicated separators — so
     * "/a/b/", "//a//b" and "https://host/a/b?x=1" all match the same rule.
     */
    public static function normalisePath(string $path): string
    {
        $path = trim($path);

        if (str_contains($path, '://')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        foreach (['?', '#'] as $cut) {
            $position = strpos($path, $cut);
            if ($position !== false) {
                $path = substr($path, 0, $position);
            }
        }

        $path = '/'.trim((string) preg_replace('#/+#', '/', $path), '/');

        return $path;
    }

    /**
     * The stored form of a rule's source. Same normalisation as a request path,
     * except a query string is kept (with its parameters sorted) — that is what
     * makes a query-specific rule like "/product?id=5" possible while still
     * matching "?id=5" however the parameters were ordered.
     */
    public static function normaliseSource(string $source): string
    {
        $path = self::normalisePath($source);
        $query = (string) parse_url(str_contains($source, '://') ? $source : 'http://x'.$source, PHP_URL_QUERY);

        return $query === '' ? $path : $path.'?'.self::canonicalQuery($query);
    }

    /**
     * The first rule matching this path, exact rules winning over patterns. A
     * rule whose source carries its own query string only matches when that
     * query is present, which is how query-specific rules stay specific.
     */
    private function find(string $path, string $query): ?SeoRedirect
    {
        $withQuery = $query !== '' ? $path.'?'.self::canonicalQuery($query) : null;

        foreach (array_filter([$withQuery, $path]) as $candidate) {
            $exact = $this->exact($candidate);

            if ($exact !== null) {
                return $exact;
            }
        }

        foreach ($this->regexRules() as $rule) {
            if ($this->patternMatches($rule->source_path, $path) !== null) {
                return $rule;
            }
        }

        return null;
    }

    private function exact(string $candidate): ?SeoRedirect
    {
        if (! array_key_exists($candidate, $this->exactCache)) {
            $this->exactCache[$candidate] = SeoRedirect::query()
                ->where('is_active', true)
                ->where('match_type', MatchType::Exact->value)
                ->where('source_hash', hash('sha256', $candidate))
                ->orderBy('id')
                ->first();
        }

        return $this->exactCache[$candidate];
    }

    /**
     * @return list<SeoRedirect>
     */
    private function regexRules(): array
    {
        if ($this->regexRules === null) {
            /** @var list<SeoRedirect> $rules */
            $rules = SeoRedirect::query()
                ->where('is_active', true)
                ->where('match_type', MatchType::Regex->value)
                ->orderBy('id')
                ->get()
                ->all();

            $this->regexRules = $rules;
        }

        return $this->regexRules;
    }

    /**
     * The destination for a rule, with capture groups substituted and the original
     * query re-attached when the rule asks for it.
     */
    private function targetFor(SeoRedirect $rule, string $path, string $query): ?string
    {
        if ($rule->status_code === 410 || $rule->target === null || $rule->target === '') {
            return null;
        }

        $target = $rule->target;

        if ($rule->match_type === MatchType::Regex->value) {
            $captures = $this->patternMatches($rule->source_path, $path);

            if ($captures !== null) {
                foreach ($captures as $index => $capture) {
                    $target = str_replace('$'.$index, $capture, $target);
                }
            }
        }

        if (! $this->isSafeTarget($target)) {
            return null;
        }

        // A rule whose source names the query has already accounted for it;
        // re-appending would produce /catalogue/five?id=5 from a rule written
        // precisely to replace ?id=5.
        $sourceConsumedQuery = str_contains($rule->source_path, '?');

        if ($rule->preserve_query && ! $sourceConsumedQuery && $query !== '' && ! str_contains($target, '?')) {
            $target .= '?'.$query;
        }

        return $target;
    }

    /**
     * Author-supplied patterns are compiled defensively: an invalid one is simply
     * skipped, so a typo in one rule cannot take down redirects for the site.
     *
     * @return list<string>|null Capture groups (index 0 = full match), or null when it does not match.
     */
    private function patternMatches(string $pattern, string $path): ?array
    {
        $delimited = '#'.str_replace('#', '\#', $pattern).'#';

        $result = @preg_match($delimited, $path, $matches);

        if ($result !== 1) {
            return null;
        }

        /** @var list<string> $matches */
        return $matches;
    }

    /**
     * Split a resolved target back into a path and query so the chain walker can
     * look it up. An off-site absolute URL ends the chain (null), since we have no
     * rules for another host.
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitTarget(string $target): ?array
    {
        if (! str_starts_with($target, '/')) {
            return null;
        }

        $query = (string) parse_url($target, PHP_URL_QUERY);

        return [self::normalisePath($target), $query];
    }

    /**
     * A redirect destination must be a path or an http(s) URL. This stops an
     * authored rule from turning into a javascript:/data: vector for anyone who
     * follows an old link.
     */
    private function isSafeTarget(string $target): bool
    {
        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return true;
        }

        return in_array(strtolower((string) parse_url($target, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * Query strings are compared with their parameters sorted, so "?b=2&a=1" and
     * "?a=1&b=2" resolve to the same rule.
     */
    private static function canonicalQuery(string $query): string
    {
        parse_str($query, $params);
        ksort($params);

        return http_build_query($params);
    }
}
