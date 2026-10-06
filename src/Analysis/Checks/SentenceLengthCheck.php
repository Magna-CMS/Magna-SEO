<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Long sentences are the single biggest driver of poor readability. The
 * threshold and tolerated share follow the same convention the readability
 * scores use: a sentence over 20 words is "long", and up to a quarter of them
 * running long is normal prose.
 */
final class SentenceLengthCheck implements AnalysisCheck
{
    private const LONG_WORDS = 20;

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $sentences = $input->outline->sentences($input->plainText);

        if ($sentences === []) {
            return new AnalysisResult('sentence-length', AnalysisStatus::Ok, 'No body text to analyse.');
        }

        $long = 0;
        foreach ($sentences as $sentence) {
            if ((int) preg_match_all('/\S+/u', $sentence) > self::LONG_WORDS) {
                $long++;
            }
        }

        $share = (int) round($long / count($sentences) * 100);

        $status = match (true) {
            $share <= 25 => AnalysisStatus::Good,
            $share <= 40 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = $status === AnalysisStatus::Good
            ? "{$share}% of sentences run long; that reads well."
            : "{$share}% of sentences are over ".self::LONG_WORDS.' words; split the longest ones.';

        return new AnalysisResult('sentence-length', $status, $message);
    }
}
