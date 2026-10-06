<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\ScannedSubject;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Shared logic for "two pages share the same X" checks: group subjects by a
 * case-insensitive value, then flag every member of any group of two or more.
 * Subclasses only decide which value to compare and how to name it.
 */
abstract class DuplicateCheck implements ScanCheck
{
    /**
     * The value to compare, or null to exclude a subject from the check.
     */
    abstract protected function value(SeoSubject $subject): ?string;

    abstract protected function check(): string;

    abstract protected function label(): string;

    public function run(array $subjects): array
    {
        /** @var array<string, list<ScannedSubject>> $groups */
        $groups = [];
        foreach ($subjects as $scanned) {
            $value = $this->value($scanned->subject);
            if ($value === null) {
                continue;
            }
            $normalised = mb_strtolower(trim($value));
            if ($normalised === '') {
                continue;
            }
            $groups[$normalised][] = $scanned;
        }

        $issues = [];
        foreach ($groups as $group) {
            $count = count($group);
            if ($count < 2) {
                continue;
            }

            foreach ($group as $scanned) {
                $issues[] = new ScanIssue(
                    source: $scanned->source,
                    subjectKey: $scanned->subject->key,
                    url: $scanned->subject->url !== '' ? $scanned->subject->url : null,
                    check: $this->check(),
                    severity: Severity::Warning,
                    message: "Duplicate {$this->label()} shared by {$count} pages.",
                );
            }
        }

        return $issues;
    }
}
