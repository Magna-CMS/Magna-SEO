<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Keeps keyword usage in a healthy band: enough to be relevant, not so much it
 * reads as stuffing (which search engines penalise).
 */
final class KeywordDensityCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-density', AnalysisStatus::Ok, 'Set a focus keyword to measure density.');
        }

        $words = $input->wordCount();
        if ($words === 0) {
            return new AnalysisResult('keyword-density', AnalysisStatus::Bad, 'There is no content to measure keyword density against.');
        }

        $occurrences = substr_count(mb_strtolower($input->plainText), mb_strtolower($input->keyword));
        $density = $occurrences / $words * 100;
        $shown = number_format($density, 1);

        if ($occurrences === 0) {
            return new AnalysisResult('keyword-density', AnalysisStatus::Bad, 'The focus keyword does not appear in the content.');
        }

        if ($density > 4.0) {
            return new AnalysisResult('keyword-density', AnalysisStatus::Bad, "Keyword density is {$shown}% — reduce it to avoid keyword stuffing.");
        }

        if ($density >= 0.5 && $density <= 2.5) {
            return new AnalysisResult('keyword-density', AnalysisStatus::Good, "Keyword density is {$shown}% — within the healthy range.");
        }

        return new AnalysisResult('keyword-density', AnalysisStatus::Ok, "Keyword density is {$shown}% — aim for roughly 0.5–2.5%.");
    }
}
