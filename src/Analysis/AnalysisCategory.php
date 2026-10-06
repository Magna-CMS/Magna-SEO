<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * Which kind of problem a check reports.
 *
 * One blended score hides the thing an author most needs to know: whether the
 * page is failing because search engines cannot tell what it is about, or
 * because people cannot read it. Those call for completely different edits, so
 * they are scored and shown separately.
 */
enum AnalysisCategory: string
{
    /** Can a search engine tell what this page is for? */
    case Seo = 'seo';

    /** Can a person read it without effort? */
    case Readability = 'readability';

    public function label(): string
    {
        return match ($this) {
            self::Seo => 'SEO',
            self::Readability => 'Readability',
        };
    }

    /**
     * The category each shipped check belongs to.
     *
     * A map rather than a method on every check: the assignment is a product
     * decision that is easier to review in one list than spread across eighteen
     * files, and a test asserts every registered check appears here.
     *
     * @var array<string, string>
     */
    private const ASSIGNMENTS = [
        'keyword-in-title' => 'seo',
        'keyword-in-description' => 'seo',
        'keyword-in-slug' => 'seo',
        'keyword-in-opening' => 'seo',
        'keyword-in-heading' => 'seo',
        'keyword-density' => 'seo',
        'content-length' => 'seo',
        'title-length' => 'seo',
        'description-length' => 'seo',
        'links' => 'seo',
        'image-alt' => 'seo',
        'page-weight' => 'seo',

        'heading-structure' => 'readability',
        'paragraph-length' => 'readability',
        'sentence-length' => 'readability',
        'passive-voice' => 'readability',
        'transition-words' => 'readability',
        'reading-ease' => 'readability',
    ];

    /**
     * An unmapped check counts toward the SEO score, which is the safer default:
     * a new technical check is far more likely to be an SEO one, and either way
     * it is counted rather than silently dropped.
     */
    public static function for(string $check): self
    {
        $assigned = self::ASSIGNMENTS[$check] ?? null;

        return $assigned !== null ? self::from($assigned) : self::Seo;
    }

    public static function isMapped(string $check): bool
    {
        return isset(self::ASSIGNMENTS[$check]);
    }
}
