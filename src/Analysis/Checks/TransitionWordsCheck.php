<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Transition words are what turn a list of statements into an argument a reader
 * can follow. Around a third of sentences carrying one is the usual mark of
 * well-connected prose. English only.
 */
final class TransitionWordsCheck implements AnalysisCheck
{
    private const WORDS = [
        'accordingly', 'additionally', 'also', 'although', 'as a result', 'because', 'before',
        'besides', 'but', 'consequently', 'conversely', 'earlier', 'equally', 'even so',
        'finally', 'first', 'for example', 'for instance', 'furthermore', 'hence', 'however',
        'in addition', 'in contrast', 'in fact', 'in other words', 'in short', 'instead',
        'later', 'likewise', 'meanwhile', 'moreover', 'nevertheless', 'next', 'nonetheless',
        'on the other hand', 'otherwise', 'rather', 'second', 'similarly', 'since', 'so',
        'still', 'that is', 'then', 'therefore', 'though', 'thus', 'to sum up', 'unless',
        'until', 'whereas', 'while', 'yet',
    ];

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $sentences = $input->outline->sentences($input->plainText);

        if ($sentences === []) {
            return new AnalysisResult('transition-words', AnalysisStatus::Ok, 'No body text to analyse.');
        }

        $withTransition = 0;
        foreach ($sentences as $sentence) {
            if ($this->hasTransition($sentence)) {
                $withTransition++;
            }
        }

        $share = (int) round($withTransition / count($sentences) * 100);

        $status = match (true) {
            $share >= 30 => AnalysisStatus::Good,
            $share >= 20 => AnalysisStatus::Ok,
            default => AnalysisStatus::Bad,
        };

        $message = $status === AnalysisStatus::Good
            ? "{$share}% of sentences contain a transition word."
            : "Only {$share}% of sentences contain a transition word; connect your ideas more explicitly.";

        return new AnalysisResult('transition-words', $status, $message);
    }

    private function hasTransition(string $sentence): bool
    {
        $stripped = preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower($sentence)) ?? $sentence;
        $text = ' '.trim((string) preg_replace('/\s+/u', ' ', $stripped)).' ';

        foreach (self::WORDS as $word) {
            if (str_contains($text, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }
}
