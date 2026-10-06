<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Rewards using the focus keyword early — within the first 100 words — where it
 * carries the most weight for both readers and crawlers.
 */
final class KeywordInFirstParagraphCheck implements AnalysisCheck
{
    private const OPENING_WORDS = 100;

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-in-opening', AnalysisStatus::Ok, 'Set a focus keyword to check the opening.');
        }

        $words = preg_split('/\s+/u', trim($input->plainText)) ?: [];
        $opening = implode(' ', array_slice($words, 0, self::OPENING_WORDS));

        $found = mb_stripos($opening, $input->keyword) !== false;

        return new AnalysisResult(
            'keyword-in-opening',
            $found ? AnalysisStatus::Good : AnalysisStatus::Bad,
            $found ? 'The focus keyword appears early in the content.' : 'Mention the focus keyword within the first paragraph.',
        );
    }
}
