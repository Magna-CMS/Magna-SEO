<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Passive-voice share, detected as a form of "to be" followed by a past
 * participle. This is a heuristic, not a parser: it over-counts some adjectival
 * constructions and misses rarer irregular participles, so the thresholds are
 * lenient and the advice is phrased as a suggestion. English only, like the
 * readability score.
 */
final class PassiveVoiceCheck implements AnalysisCheck
{
    private const BE = ['is', 'are', 'was', 'were', 'be', 'been', 'being'];

    /** Common past participles that do not end in -ed. */
    private const IRREGULAR = [
        'given', 'taken', 'made', 'done', 'seen', 'known', 'written', 'built', 'sent', 'found',
        'held', 'kept', 'left', 'lost', 'meant', 'met', 'paid', 'put', 'read', 'said', 'sold',
        'shown', 'told', 'thought', 'understood', 'won', 'brought', 'bought', 'caught', 'chosen',
        'driven', 'eaten', 'fallen', 'forgotten', 'gotten', 'heard', 'hidden', 'set',
    ];

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $sentences = $input->outline->sentences($input->plainText);

        if ($sentences === []) {
            return new AnalysisResult('passive-voice', AnalysisStatus::Ok, 'No body text to analyse.');
        }

        $passive = 0;
        foreach ($sentences as $sentence) {
            if ($this->looksPassive($sentence)) {
                $passive++;
            }
        }

        $share = (int) round($passive / count($sentences) * 100);

        $status = match (true) {
            $share <= 10 => AnalysisStatus::Good,
            $share <= 20 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = $status === AnalysisStatus::Good
            ? "{$share}% of sentences use the passive voice; that is fine."
            : "{$share}% of sentences look passive; rewriting some in the active voice reads better.";

        return new AnalysisResult('passive-voice', $status, $message);
    }

    private function looksPassive(string $sentence): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $index => $word) {
            if (! in_array($word, self::BE, true)) {
                continue;
            }

            // Allow one adverb between the auxiliary and the participle,
            // as in "was quickly replaced".
            foreach ([1, 2] as $offset) {
                $candidate = $words[$index + $offset] ?? null;

                if ($candidate !== null && $this->isParticiple($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isParticiple(string $word): bool
    {
        if (in_array($word, self::IRREGULAR, true)) {
            return true;
        }

        return mb_strlen($word) > 4 && str_ends_with($word, 'ed');
    }
}
