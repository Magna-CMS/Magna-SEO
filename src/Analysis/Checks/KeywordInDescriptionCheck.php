<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

final class KeywordInDescriptionCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-in-description', AnalysisStatus::Ok, 'Set a focus keyword to check the description.');
        }

        $found = mb_stripos($input->description, $input->keyword) !== false;

        return new AnalysisResult(
            'keyword-in-description',
            $found ? AnalysisStatus::Good : AnalysisStatus::Bad,
            $found ? 'The focus keyword appears in the meta description.' : 'Add the focus keyword to the meta description.',
        );
    }
}
