<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * The full analysis of a page: an SEO score, a readability score, and the
 * per-check results that explain both.
 *
 * Two scores rather than one, because they are fixed by different edits. A page
 * can be perfectly findable and unreadable, or beautifully written about nothing
 * in particular, and an author needs to be told which.
 *
 * `score` remains the blend of the two, kept for callers that want a single
 * number to sort or store.
 */
final readonly class AnalysisReport
{
    /** Below this a score is red; above the amber ceiling it is green. */
    public const NEEDS_WORK_BELOW = 41;

    public const GOOD_ABOVE = 70;

    /**
     * @param  list<AnalysisResult>  $results
     */
    public function __construct(
        public int $score,
        public array $results,
        public int $seoScore = 0,
        public int $readabilityScore = 0,
    ) {}

    /**
     * @param  list<AnalysisResult>  $results
     */
    public static function fromResults(array $results): self
    {
        $seo = self::scoreOf($results, AnalysisCategory::Seo);
        $readability = self::scoreOf($results, AnalysisCategory::Readability);

        return new self(
            score: self::scoreOf($results, null),
            results: $results,
            seoScore: $seo,
            readabilityScore: $readability,
        );
    }

    /**
     * @return list<AnalysisResult>
     */
    public function resultsFor(AnalysisCategory $category): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (AnalysisResult $result): bool => AnalysisCategory::for($result->check) === $category,
        ));
    }

    public function scoreFor(AnalysisCategory $category): int
    {
        return $category === AnalysisCategory::Seo ? $this->seoScore : $this->readabilityScore;
    }

    /**
     * The traffic-light band for a score. The thresholds match the convention
     * editors already know from other SEO tools, so a green here means what they
     * expect green to mean.
     */
    public static function band(int $score): AnalysisStatus
    {
        return match (true) {
            $score < self::NEEDS_WORK_BELOW => AnalysisStatus::Bad,
            $score <= self::GOOD_ABOVE => AnalysisStatus::Ok,
            default => AnalysisStatus::Good,
        };
    }

    /**
     * A word for the band. Colour alone is not a label — around one man in
     * twelve cannot reliably tell the red dot from the green one.
     */
    public static function bandLabel(int $score): string
    {
        return match (self::band($score)) {
            AnalysisStatus::Bad => 'Needs work',
            AnalysisStatus::Ok => 'OK',
            AnalysisStatus::Good => 'Good',
        };
    }

    /**
     * @return array{score: int, seo_score: int, readability_score: int, results: list<array{check: string, category: string, status: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'seo_score' => $this->seoScore,
            'readability_score' => $this->readabilityScore,
            'results' => array_map(
                static fn (AnalysisResult $r): array => [
                    'check' => $r->check,
                    'category' => AnalysisCategory::for($r->check)->value,
                    'status' => $r->status->value,
                    'message' => $r->message,
                ],
                $this->results,
            ),
        ];
    }

    /**
     * Mean of the check weights, as a percentage. A category with no checks
     * scores 0 rather than dividing by zero.
     *
     * @param  list<AnalysisResult>  $results
     */
    private static function scoreOf(array $results, ?AnalysisCategory $category): int
    {
        $relevant = $category === null
            ? $results
            : array_filter($results, static fn (AnalysisResult $r): bool => AnalysisCategory::for($r->check) === $category);

        if ($relevant === []) {
            return 0;
        }

        $sum = 0.0;
        foreach ($relevant as $result) {
            $sum += $result->status->weight();
        }

        return (int) round($sum / count($relevant) * 100);
    }
}
