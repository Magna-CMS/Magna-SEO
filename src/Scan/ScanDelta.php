<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;

/**
 * What changed between two scans.
 *
 * Reporting totals is useless past a certain site size: "412 issues" is the same
 * number every week and teaches an owner to ignore the message. What is worth
 * interrupting someone for is the change — six errors that were not there on
 * Tuesday, or eleven that someone fixed and deserves to know about.
 *
 * Findings are identified by (check, subject) rather than by row id, because a
 * scan writes new rows every run; the same problem on the same page is the same
 * finding even though its id changed.
 */
final readonly class ScanDelta
{
    /**
     * @param  list<SeoScanIssue>  $newIssues  Present now, absent last time.
     * @param  int  $resolved  Present last time, absent now.
     */
    public function __construct(
        public array $newIssues,
        public int $resolved,
        public int $totalIssues,
        public bool $isFirstScan,
    ) {}

    public static function between(SeoScan $current, ?SeoScan $previous): self
    {
        $currentIssues = $current->issues()->get()->all();

        if ($previous === null) {
            return new self(
                newIssues: array_values($currentIssues),
                resolved: 0,
                totalIssues: count($currentIssues),
                isFirstScan: true,
            );
        }

        $before = [];
        foreach ($previous->issues()->get() as $issue) {
            $before[self::fingerprint($issue)] = true;
        }

        $new = [];
        $seenNow = [];

        foreach ($currentIssues as $issue) {
            $fingerprint = self::fingerprint($issue);
            $seenNow[$fingerprint] = true;

            if (! isset($before[$fingerprint])) {
                $new[] = $issue;
            }
        }

        $resolved = count(array_diff_key($before, $seenNow));

        return new self(
            newIssues: $new,
            resolved: $resolved,
            totalIssues: count($currentIssues),
            isFirstScan: false,
        );
    }

    /**
     * New findings at error severity — the only ones worth interrupting someone
     * for. Warnings and notices belong on the dashboard, not in a notification.
     *
     * @return list<SeoScanIssue>
     */
    public function newErrors(): array
    {
        return array_values(array_filter(
            $this->newIssues,
            static fn (SeoScanIssue $issue): bool => $issue->severity === Severity::Error,
        ));
    }

    /**
     * Whether this scan is worth telling anyone about at all.
     */
    public function isNoteworthy(): bool
    {
        return $this->newErrors() !== [] || ($this->isFirstScan && $this->totalIssues > 0);
    }

    private static function fingerprint(SeoScanIssue $issue): string
    {
        return $issue->check."\0".$issue->subject_key;
    }
}
