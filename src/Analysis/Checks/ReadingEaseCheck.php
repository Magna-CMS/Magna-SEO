<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\ReadingEase;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Scores readability with Flesch Reading Ease (English). Skipped for very short
 * bodies where the score is not meaningful.
 */
final class ReadingEaseCheck implements AnalysisCheck
{
    public function __construct(private readonly ReadingEase $readingEase) {}

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if ($input->wordCount() < 50) {
            return new AnalysisResult('reading-ease', AnalysisStatus::Ok, 'Not enough content to measure readability.');
        }

        $score = $this->readingEase->score($input->plainText);
        $shown = number_format($score, 0);

        $status = match (true) {
            $score >= 60 => AnalysisStatus::Good,
            $score >= 30 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = match ($status) {
            AnalysisStatus::Good => "Reading ease is good ({$shown}).",
            AnalysisStatus::Ok => "Reading ease is fair ({$shown}); shorter sentences would help.",
            AnalysisStatus::Bad => "Text is hard to read ({$shown}); simplify sentences and words.",
        };

        return new AnalysisResult('reading-ease', $status, $message);
    }
}
