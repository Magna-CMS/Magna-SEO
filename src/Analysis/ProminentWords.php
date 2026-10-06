<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * What a page is *actually* about, as opposed to what its author declared.
 *
 * The most common cause of a page that will not rank is a mismatch between the
 * two: the focus keyword says "espresso machines", the text is mostly about
 * grinders. Counting the terms that genuinely dominate the text surfaces that in
 * one glance, and gives the internal-link suggester something to match on.
 *
 * Stop words are removed because "the" appearing four hundred times is not a
 * finding. English only, matching the readability checks; other languages get
 * the raw frequency, which is still better than nothing but should not be
 * presented as insight.
 */
final class ProminentWords
{
    private const STOP_WORDS = [
        'a', 'about', 'after', 'again', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
        'be', 'because', 'been', 'before', 'being', 'between', 'both', 'but', 'by', 'can', 'did',
        'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for', 'from', 'further', 'had',
        'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'him', 'his', 'how', 'i', 'if', 'in',
        'into', 'is', 'it', 'its', 'itself', 'just', 'me', 'more', 'most', 'my', 'no', 'nor', 'not',
        'now', 'of', 'off', 'on', 'once', 'only', 'or', 'other', 'our', 'ours', 'out', 'over', 'own',
        'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'them',
        'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too', 'under', 'until',
        'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom',
        'why', 'will', 'with', 'would', 'you', 'your', 'yours',
    ];

    /**
     * The most frequent meaningful words, longest-tail first.
     *
     * @return list<array{word: string, count: int}>
     */
    public static function of(string $text, int $limit = 10): array
    {
        $counts = self::counts($text);
        arsort($counts);

        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $word => $count) {
            $out[] = ['word' => $word, 'count' => $count];
        }

        return $out;
    }

    /**
     * Just the words, for matching one page's subject against another's.
     *
     * @return list<string>
     */
    public static function terms(string $text, int $limit = 20): array
    {
        return array_map(
            static fn (array $row): string => $row['word'],
            self::of($text, $limit),
        );
    }

    /**
     * @return array<string, int>
     */
    private static function counts(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}\'-]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];

        foreach ($words as $word) {
            $word = trim($word, "'-");

            // Two-letter words carry no topical signal even when they are not
            // stop words, and numbers on their own are almost never the subject.
            if (mb_strlen($word) < 3 || is_numeric($word) || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        return $counts;
    }
}
