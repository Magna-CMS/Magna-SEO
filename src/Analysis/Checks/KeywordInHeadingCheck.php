<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * The focus keyword should appear in a heading — the H1 above all, since it is
 * the strongest on-page signal after the title tag.
 */
final class KeywordInHeadingCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-in-heading', AnalysisStatus::Ok, 'Set a focus keyword to check your headings.');
        }

        if (! $input->outline->hasMarkup) {
            return new AnalysisResult('keyword-in-heading', AnalysisStatus::Ok, 'No markup available to check headings.');
        }

        $keyword = mb_strtolower($input->keyword);
        $inH1 = false;
        $inOther = false;

        foreach ($input->outline->headings as $heading) {
            if (! str_contains(mb_strtolower($heading['text']), $keyword)) {
                continue;
            }

            if ($heading['level'] === 1) {
                $inH1 = true;
            } else {
                $inOther = true;
            }
        }

        return match (true) {
            $inH1 => new AnalysisResult('keyword-in-heading', AnalysisStatus::Good, 'The focus keyword appears in the H1.'),
            $inOther => new AnalysisResult('keyword-in-heading', AnalysisStatus::Ok, 'The focus keyword appears in a subheading but not the H1.'),
            default => new AnalysisResult('keyword-in-heading', AnalysisStatus::Bad, 'The focus keyword appears in no heading; work it into the H1.'),
        };
    }
}
