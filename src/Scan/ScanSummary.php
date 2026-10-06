<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Illuminate\Support\Collection;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;

/**
 * Read-side rollups of scan results for the SEO Health dashboard. Kept separate
 * from the scanner so the presentation queries are testable on their own.
 */
final class ScanSummary
{
    public function latest(): ?SeoScan
    {
        return SeoScan::query()->latest('id')->first();
    }

    /**
     * Issue counts keyed by severity value (error/warning/notice).
     *
     * @return array<string, int>
     */
    public function countsBySeverity(SeoScan $scan): array
    {
        $counts = [];
        foreach ($scan->issues()->get(['severity']) as $issue) {
            $key = $issue->severity->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Issue counts keyed by check slug, most frequent first.
     *
     * @return array<string, int>
     */
    public function countsByCheck(SeoScan $scan): array
    {
        $counts = [];
        foreach ($scan->issues()->get(['check']) as $issue) {
            $counts[$issue->check] = ($counts[$issue->check] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return Collection<int, SeoScanIssue>
     */
    public function recentIssues(SeoScan $scan, int $limit = 50): Collection
    {
        return $scan->issues()->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Findings regrouped by the page they are about, worst page first.
     *
     * The flat list answers "what is wrong with this site". Editors ask a
     * different question — "which page do I open next" — and a flat list answers
     * that badly, scattering one page's four problems across four rows with no
     * sign they belong together.
     *
     * Ordering is by severity first and volume second, so a single page with one
     * error outranks a page with six notices: an error is a page search engines
     * may refuse outright, and notices are advice.
     *
     * @return list<array{url: string|null, source: string, title: string, worst: string, count: int, issues: list<SeoScanIssue>}>
     */
    public function issuesByPage(SeoScan $scan, int $limit = 25): array
    {
        $weights = ['error' => 3, 'warning' => 2, 'notice' => 1];

        $groups = [];

        foreach ($scan->issues()->orderByDesc('id')->get() as $issue) {
            // Site-level findings (robots.txt, redirect loops) carry no URL.
            // They are grouped under their check so they stay visible rather
            // than collapsing into one nameless bucket.
            $key = $issue->url ?: 'site:'.$issue->check;

            $groups[$key]['url'] ??= $issue->url;
            $groups[$key]['source'] ??= $issue->source;
            $groups[$key]['issues'][] = $issue;
        }

        $rows = [];

        foreach ($groups as $key => $group) {
            $severities = array_map(
                static fn (SeoScanIssue $issue): string => $issue->severity->value,
                $group['issues'],
            );

            usort($severities, static fn (string $a, string $b): int => ($weights[$b] ?? 0) <=> ($weights[$a] ?? 0));

            $rows[] = [
                'url' => $group['url'] ?? null,
                'source' => $group['source'] ?? '',
                'title' => $this->pageTitle($group['url'] ?? null, $key),
                'worst' => $severities[0] ?? 'notice',
                'count' => count($group['issues']),
                'issues' => $group['issues'],
            ];
        }

        usort($rows, static function (array $a, array $b) use ($weights): int {
            return [$weights[$b['worst']] ?? 0, $b['count']] <=> [$weights[$a['worst']] ?? 0, $a['count']];
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * A readable name for a grouped page. Scan issues store the URL, not the
     * title, so the path is the best label available without a per-row lookup
     * against every content source.
     */
    private function pageTitle(?string $url, string $key): string
    {
        if ($url === null || $url === '') {
            return 'Site-wide';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' && $path !== '/' ? $path : 'Home page';
    }
}
