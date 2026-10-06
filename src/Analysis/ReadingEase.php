<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * Flesch Reading Ease for English text. Higher is easier (0–100). Syllable
 * counting is a well-known heuristic (vowel groups minus a trailing silent "e"),
 * good enough for a relative readability signal, not linguistic precision.
 */
final class ReadingEase
{
    public function score(string $text): float
    {
        $sentences = max(1, (int) preg_match_all('/[.!?]+/u', $text));
        $words = $this->words($text);
        $wordCount = count($words);

        if ($wordCount === 0) {
            return 0.0;
        }

        $syllables = 0;
        foreach ($words as $word) {
            $syllables += $this->syllables($word);
        }

        $score = 206.835
            - 1.015 * ($wordCount / $sentences)
            - 84.6 * ($syllables / $wordCount);

        return max(0.0, min(100.0, round($score, 1)));
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\']+/u', $text, $matches);

        /** @var list<string> $words */
        $words = $matches[0];

        return $words;
    }

    private function syllables(string $word): int
    {
        $word = strtolower(preg_replace('/[^a-z]/i', '', $word) ?? '');
        if ($word === '') {
            return 1;
        }

        $groups = preg_match_all('/[aeiouy]+/', $word);
        $count = is_int($groups) ? $groups : 0;

        // A trailing silent "e" usually does not add a syllable.
        if (str_ends_with($word, 'e')) {
            $count--;
        }

        return max(1, $count);
    }
}
