<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

final class KeywordInTitleCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-in-title', AnalysisStatus::Ok, 'Set a focus keyword to check the title.');
        }

        $found = mb_stripos($input->title, $input->keyword) !== false;

        return new AnalysisResult(
            'keyword-in-title',
            $found ? AnalysisStatus::Good : AnalysisStatus::Bad,
            $found ? 'The focus keyword appears in the title.' : 'Add the focus keyword to the title.',
        );
    }
}
