<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Meta descriptions around 120–160 characters use the available snippet width
 * without being cut off.
 */
final class DescriptionLengthCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $length = mb_strlen(trim($input->description));

        if ($length === 0) {
            return new AnalysisResult('description-length', AnalysisStatus::Bad, 'Add a meta description.');
        }

        $status = match (true) {
            $length >= 120 && $length <= 160 => AnalysisStatus::Good,
            $length >= 70 && $length <= 180 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = match ($status) {
            AnalysisStatus::Good => "Description length is good ({$length} characters).",
            AnalysisStatus::Ok => "Description length is acceptable ({$length} characters); aim for 120–160.",
            AnalysisStatus::Bad => $length < 120
                ? "Description is short ({$length} characters); use the available space."
                : "Description is long ({$length} characters); it may be truncated.",
        };

        return new AnalysisResult('description-length', $status, $message);
    }
}
