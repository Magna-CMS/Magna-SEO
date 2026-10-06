<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Titles roughly 30–60 characters tend to render without truncation in search
 * results while still being descriptive.
 */
final class TitleLengthCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $length = mb_strlen(trim($input->title));

        $status = match (true) {
            $length >= 30 && $length <= 60 => AnalysisStatus::Good,
            $length >= 20 && $length <= 70 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = match ($status) {
            AnalysisStatus::Good => "Title length is good ({$length} characters).",
            AnalysisStatus::Ok => "Title length is acceptable ({$length} characters); aim for 30–60.",
            AnalysisStatus::Bad => $length < 30
                ? "Title is short ({$length} characters); make it more descriptive."
                : "Title is long ({$length} characters); it may be truncated in results.",
        };

        return new AnalysisResult('title-length', $status, $message);
    }
}
