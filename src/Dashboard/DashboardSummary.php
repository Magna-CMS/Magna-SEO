<?php

declare(strict_types=1);

namespace Magna\Seo\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Seo\Integrations\SearchConsole;
use Magna\Seo\Models\SeoNotFound;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Support\FixInstructions;

/**
 * Everything the SEO dashboard shows, assembled here so the page stays a view
 * and the numbers stay testable.
 *
 * Two rules shape it. Nothing blocks on a live API call — Search Console reads
 * come from its own cache and return null when unavailable, so a tile shows a
 * connect prompt instead of a spinner. And every figure carries the window it
 * covers; a number with no period attached cannot be trusted or compared.
 */
final class DashboardSummary
{
    /**
     * The checks that run once per page, so a pass rate has a real denominator.
     * Site-level checks (robots.txt, the redirect table) are counted, not rated.
     */
    private const PER_PAGE_CHECKS = [
        'missing-title' => 'Titles',
        'missing-description' => 'Meta descriptions',
        'schema-invalid' => 'Structured data',
        'missing-image-alt' => 'Image alt text',
        'heavy-page' => 'Page weight',
        'thin-content' => 'Content depth',
        'orphan-page' => 'Internal linking',
    ];

    public function __construct(private readonly SearchConsole $searchConsole) {}

    public function latestScan(): ?SeoScan
    {
        return SeoScan::query()->latest('id')->first();
    }

    /**
     * Site health, with its movement since the previous scan.
     *
     * @return array{score: int|null, change: int|null, scannedAt: string|null, urls: int}
     */
    public function health(): array
    {
        $latest = $this->latestScan();

        if ($latest === null) {
            return ['score' => null, 'change' => null, 'scannedAt' => null, 'urls' => 0];
        }

        $previous = SeoScan::query()
            ->where('id', '<', $latest->id)
            ->whereNotNull('score')
            ->latest('id')
            ->first();

        return [
            'score' => $latest->score,
            'change' => $latest->score !== null && $previous?->score !== null
                ? $latest->score - $previous->score
                : null,
            'scannedAt' => $latest->created_at?->diffForHumans(),
            'urls' => $latest->url_count,
        ];
    }

    /**
     * Issue counts by severity, plus the single most common problem — which is
     * usually where an hour of work removes the most findings.
     *
     * @return array{error: int, warning: int, notice: int, topCheck: array{check: string, label: string, count: int, fix: string|null}|null}
     */
    public function issues(): array
    {
        $latest = $this->latestScan();

        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];

        if ($latest === null) {
            return [...$counts, 'topCheck' => null];
        }

        foreach ($latest->issues()->get(['severity']) as $issue) {
            $counts[$issue->severity->value]++;
        }

        $stats = $latest->check_stats ?? [];
        arsort($stats);
        $topCheck = array_key_first($stats);

        return [
            ...$counts,
            'topCheck' => $topCheck === null ? null : [
                'check' => $topCheck,
                'label' => self::PER_PAGE_CHECKS[$topCheck] ?? $topCheck,
                'count' => $stats[$topCheck],
                'fix' => FixInstructions::for($topCheck),
            ],
        ];
    }

    /**
     * Pass rate per per-page check, worst first — the order in which they are
     * worth working through.
     *
     * @return list<array{check: string, label: string, rate: int, failed: int}>
     */
    public function checklist(): array
    {
        $latest = $this->latestScan();

        if ($latest === null) {
            return [];
        }

        $rows = [];

        foreach (self::PER_PAGE_CHECKS as $check => $label) {
            $failed = $latest->check_stats[$check] ?? 0;
            $rate = $latest->passRateFor($check) ?? ($latest->url_count > 0 ? 100 : 0);

            $rows[] = ['check' => $check, 'label' => $label, 'rate' => $rate, 'failed' => $failed];
        }

        usort($rows, static fn (array $a, array $b): int => $a['rate'] <=> $b['rate']);

        return $rows;
    }

    /**
     * Search performance, or null when Search Console is not connected. Callers
     * show a connect prompt for null rather than zeroes, because zero clicks and
     * "we cannot see your clicks" are very different statements.
     *
     * @return array{totals: array<string, mixed>|null, series: list<array{date: string, clicks: int, impressions: int}>|null, queries: list<array<string, mixed>>|null, connected: bool}
     */
    public function search(int $days = 28): array
    {
        if (! $this->searchConsole->isConfigured()) {
            return ['totals' => null, 'series' => null, 'queries' => null, 'connected' => false];
        }

        return [
            'totals' => $this->searchConsole->siteTotals($days),
            'series' => $this->searchConsole->dailySeries($days),
            'queries' => $this->searchConsole->topQueries($days),
            'connected' => true,
        ];
    }

    /**
     * How many dead URLs are being hit, which is the cheapest traffic on a site
     * to recover: the visitors are already arriving.
     */
    public function notFoundCount(): int
    {
        return SeoNotFound::query()->count();
    }

    /**
     * Queries ranking just off the first page. Nothing else on the dashboard is
     * as actionable: these pages already rank, and a modest improvement moves
     * them somewhere people actually look.
     *
     * @param  list<array<string, mixed>>|null  $queries
     * @return list<array<string, mixed>>
     */
    public function strikingDistance(?array $queries): array
    {
        if ($queries === null) {
            return [];
        }

        $near = array_values(array_filter($queries, static function (array $row): bool {
            $position = (float) ($row['position'] ?? 0);

            return $position > 10.0 && $position <= 20.0;
        }));

        usort($near, static fn (array $a, array $b): int => ($b['impressions'] ?? 0) <=> ($a['impressions'] ?? 0));

        return array_slice($near, 0, 5);
    }

    public function severityLabel(Severity $severity): string
    {
        return ucfirst($severity->value);
    }

    /**
     * Whether queued work is actually being carried out.
     *
     * This exists because of a specific, silent failure: the dashboard used to
     * queue its scan and report success, so on any install without a running
     * worker the button did nothing at all — forever, with no error. Rather than
     * assume a worker, ask.
     *
     * Proof of absence is the only reliable signal available: jobs sitting in the
     * table long past their dispatch mean nobody is draining it. An empty table
     * proves nothing either way, so it is treated as healthy.
     *
     * @return array{driver: string, pending: int, stalledMinutes: int|null, healthy: bool}
     */
    public function queueHealth(): array
    {
        $driver = (string) config('queue.default');

        // Sync runs inline on dispatch, so there is nothing to drain.
        if ($driver === 'sync') {
            return ['driver' => $driver, 'pending' => 0, 'stalledMinutes' => null, 'healthy' => true];
        }

        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            // Redis, SQS and friends keep their depth elsewhere. Reading it would
            // mean a live connection on every dashboard render, which this page
            // does not do; claiming they are broken would be worse than silence.
            return ['driver' => $driver, 'pending' => 0, 'stalledMinutes' => null, 'healthy' => true];
        }

        $pending = (int) DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');

        $minutes = $oldest === null ? null : (int) floor((time() - (int) $oldest) / 60);

        return [
            'driver' => $driver,
            'pending' => $pending,
            'stalledMinutes' => $minutes,
            // Ten minutes: long enough that a slow job or a worker restart does
            // not raise a false alarm, short enough to catch a real outage the
            // same working day.
            'healthy' => $minutes === null || $minutes < 10,
        ];
    }

    public function queueIsProcessing(): bool
    {
        return $this->queueHealth()['healthy'];
    }
}
