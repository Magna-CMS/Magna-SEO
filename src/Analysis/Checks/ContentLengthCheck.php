<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

final class ContentLengthCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $words = $input->wordCount();

        $status = match (true) {
            $words >= 300 => AnalysisStatus::Good,
            $words >= 150 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = match ($status) {
            AnalysisStatus::Good => "Content length is good ({$words} words).",
            AnalysisStatus::Ok => "Content is a little short ({$words} words); aim for 300+.",
            AnalysisStatus::Bad => "Content is thin ({$words} words); add more depth.",
        };

        return new AnalysisResult('content-length', $status, $message);
    }
}
