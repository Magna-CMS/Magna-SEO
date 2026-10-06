<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * A wall of text loses readers on a phone long before it loses a crawler. Long
 * paragraphs are counted individually rather than as a percentage, because one
 * enormous paragraph is the actual thing to go and fix.
 */
final class ParagraphLengthCheck implements AnalysisCheck
{
    private const LONG_WORDS = 150;

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $paragraphs = $input->outline->paragraphs;

        if ($paragraphs === []) {
            return new AnalysisResult('paragraph-length', AnalysisStatus::Ok, 'No paragraphs to analyse.');
        }

        $long = 0;
        foreach ($paragraphs as $paragraph) {
            if ((int) preg_match_all('/\S+/u', $paragraph) > self::LONG_WORDS) {
                $long++;
            }
        }

        return match (true) {
            $long === 0 => new AnalysisResult('paragraph-length', AnalysisStatus::Good, 'No overlong paragraphs.'),
            $long === 1 => new AnalysisResult('paragraph-length', AnalysisStatus::Ok, 'One paragraph is over '.self::LONG_WORDS.' words; consider splitting it.'),
            default => new AnalysisResult('paragraph-length', AnalysisStatus::Bad, "{$long} paragraphs are over ".self::LONG_WORDS.' words; break them up.'),
        };
    }
}
