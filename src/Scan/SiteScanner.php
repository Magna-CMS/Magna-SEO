<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Magna\Seo\Analysis\AnalysisCache;
use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scorecard\Scorecard;
use Magna\Seo\Scorecard\ScorecardResult;
use Throwable;

/**
 * Runs the static (no-HTTP) site scan: it streams every source's indexable
 * subjects into memory once, runs each registered check over the whole set
 * (checks may be per-subject or cross-subject), and persists the run and its
 * issues. Reads nothing over the network; every finding comes from the database.
 */
final class SiteScanner
{
    /**
     * @param  list<ScanCheck>  $checks
     */
    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly array $checks,
        private readonly ?ScanReporter $reporter = null,
        private readonly ?Scorecard $scorecard = null,
        private readonly ?AnalysisCache $analysisCache = null,
    ) {}

    public function scan(): SeoScan
    {
        $subjects = $this->collect();

        $issues = [];
        foreach ($this->checks as $check) {
            foreach ($check->run($subjects) as $issue) {
                $issues[] = $issue;
            }
        }

        $scan = DB::transaction(function () use ($subjects, $issues): SeoScan {
            $scan = SeoScan::create([
                'url_count' => count($subjects),
                'issue_count' => count($issues),
                'score' => $this->scoreNow(),
                'check_stats' => $this->failuresPerCheck($issues),
            ]);

            if ($issues !== []) {
                SeoScanIssue::insert($this->rows($scan->id, $issues));
            }

            return $scan;
        });

        // Refresh the per-page scores while the subjects are already in hand.
        // Without this they are only ever written when an author saves a page,
        // which means a site that had content before the plugin was installed
        // shows an empty scoreboard permanently. Outside the transaction, and
        // failure-tolerant: a score is advice, and losing it must never fail a
        // scan that has already completed.
        $this->refreshScores($subjects);

        // Outside the transaction for the same reason: a notification failure
        // must not roll back a scan that already succeeded.
        $this->reporter?->report($scan);

        return $scan;
    }

    /**
     * The scorecard result, recorded alongside the scan so a trend line can be
     * drawn later. A scorecard failure must not fail the scan.
     */
    private function scoreNow(): ?int
    {
        if ($this->scorecard === null) {
            return null;
        }

        try {
            $report = $this->scorecard->run();
            $total = count($report->results);

            if ($total === 0) {
                return null;
            }

            $passed = count(array_filter($report->results, static fn (ScorecardResult $r): bool => $r->passed));

            return (int) round($passed / $total * 100);
        } catch (Throwable $e) {
            Log::warning('SEO scan: the scorecard could not be recorded.', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * How many distinct pages each check failed on.
     *
     * Counted per subject, not per issue: a check that reports the same page
     * twice must not make that page look like two failures when the dashboard
     * turns this into a pass rate.
     *
     * @param  list<ScanIssue>  $issues
     * @return array<string, int>
     */
    private function failuresPerCheck(array $issues): array
    {
        $seen = [];

        foreach ($issues as $issue) {
            $seen[$issue->check][$issue->subjectKey] = true;
        }

        return array_map('count', $seen);
    }

    /**
     * @param  list<ScanIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function rows(int $scanId, array $issues): array
    {
        $now = now();

        return array_map(fn (ScanIssue $issue): array => [
            'seo_scan_id' => $scanId,
            'source' => $issue->source,
            'subject_key' => $issue->subjectKey,
            'url' => $issue->url,
            'check' => $issue->check,
            'severity' => $issue->severity->value,
            'message' => $issue->message,
            'created_at' => $now,
            'updated_at' => $now,
        ], $issues);
    }

    /**
     * @return list<ScannedSubject>
     */
    private function collect(): array
    {
        $subjects = [];
        foreach ($this->registry->all() as $handle => $source) {
            // A single faulty source must not abort the whole scan; log it and
            // move on, so the rest of the site is still checked.
            try {
                $source->chunk(function (array $batch) use (&$subjects, $handle): void {
                    foreach ($batch as $subject) {
                        if ($subject->indexable) {
                            $subjects[] = new ScannedSubject($handle, $subject);
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::warning('SEO scan: a source failed and was skipped.', [
                    'source' => $handle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $subjects;
    }

    /**
     * Store each scanned subject's SEO and readability scores.
     *
     * Per-subject failure tolerance is deliberate: the scan's job is to report
     * what is wrong with the site, and one page whose analysis throws must not
     * cost the other nine hundred their scores.
     *
     * @param  list<ScannedSubject>  $subjects
     */
    private function refreshScores(array $subjects): void
    {
        if ($this->analysisCache === null) {
            return;
        }

        $modelClasses = [];
        foreach ($this->registry->all() as $handle => $source) {
            $modelClasses[$handle] = $source->modelClass();
        }

        foreach ($subjects as $scanned) {
            try {
                $this->analysisCache->refresh(
                    $scanned->subject,
                    $modelClasses[$scanned->source] ?? null,
                );
            } catch (Throwable $e) {
                Log::warning('SEO scan: a page score could not be refreshed.', [
                    'subject' => $scanned->subject->key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
