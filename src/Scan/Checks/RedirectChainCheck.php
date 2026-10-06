<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Redirect rules that chain or loop.
 *
 * The runtime store collapses chains for visitors, so a chain is never *served*
 * — but the rules themselves stay wrong, and the moment they are exported,
 * imported elsewhere, or the hop ceiling is reached, the chain becomes real.
 * A loop is worse: it means one of the two rules can never do anything useful.
 *
 * This check reads only the rule table; it makes no request and does not care
 * how many subjects the site has.
 */
final class RedirectChainCheck implements ScanCheck
{
    private const MAX_WALK = 10;

    public function run(array $subjects): array
    {
        $rules = SeoRedirect::query()
            ->where('is_active', true)
            ->where('match_type', 'exact')
            ->get();

        /** @var array<string, SeoRedirect> $bySource */
        $bySource = [];

        foreach ($rules as $rule) {
            $bySource[RedirectStore::normalisePath($rule->source_path)] = $rule;
        }

        $issues = [];

        foreach ($rules as $rule) {
            if ($rule->target === null) {
                continue;
            }

            $path = RedirectStore::normalisePath($rule->source_path);
            $verdict = $this->walk($path, $bySource);

            if ($verdict === null) {
                continue;
            }

            [$kind, $hops] = $verdict;

            $issues[] = new ScanIssue(
                source: 'redirects',
                subjectKey: 'redirect:'.$rule->id,
                url: $rule->source_path,
                check: 'redirect-'.$kind,
                severity: $kind === 'loop' ? Severity::Error : Severity::Warning,
                message: $kind === 'loop'
                    ? 'This redirect eventually points back at itself.'
                    : "This redirect passes through {$hops} hops; point it straight at the final destination.",
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, SeoRedirect>  $bySource
     * @return array{0: string, 1: int}|null ['loop'|'chain', hops], or null when the rule is direct.
     */
    private function walk(string $start, array $bySource): ?array
    {
        $seen = [$start => true];
        $current = $start;
        $hops = 0;

        while ($hops < self::MAX_WALK) {
            $rule = $bySource[$current] ?? null;

            if ($rule === null || $rule->target === null) {
                break;
            }

            // Only same-site targets can chain; another host's rules are not ours.
            if (! str_starts_with($rule->target, '/')) {
                break;
            }

            $next = RedirectStore::normalisePath($rule->target);
            $hops++;

            if (isset($seen[$next])) {
                return ['loop', $hops];
            }

            $seen[$next] = true;
            $current = $next;
        }

        return $hops > 1 ? ['chain', $hops] : null;
    }
}
