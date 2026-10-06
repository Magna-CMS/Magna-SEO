<?php

declare(strict_types=1);

namespace Magna\Seo\Dashboard;

use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Every indexable page with its SEO state, in one list.
 *
 * This is the screen Yoast puts in the WordPress posts list, except it is not
 * confined to one post type: a site's SEO problems do not respect content-type
 * boundaries, and "show me everything with no description" is the question
 * people actually ask.
 *
 * Scores come from the analysis cached when each page was last saved. Nothing is
 * analysed while this renders — a list of five hundred pages must not run five
 * hundred analyses.
 *
 * @phpstan-type ContentRow array{
 *     source: string, key: string, modelType: string|null, modelId: string|null,
 *     title: string, url: string, description: string|null, keyword: string|null,
 *     seoScore: int|null, readabilityScore: int|null, indexable: bool
 * }
 */
final class ContentOverview
{
    public const FILTER_ALL = 'all';

    public const FILTER_NEEDS_WORK = 'needs-work';

    public const FILTER_NO_DESCRIPTION = 'no-description';

    public const FILTER_NO_KEYWORD = 'no-keyword';

    public const FILTER_NOINDEX = 'noindex';

    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function filters(): array
    {
        return [
            self::FILTER_ALL => 'All pages',
            self::FILTER_NEEDS_WORK => 'Needs work',
            self::FILTER_NO_DESCRIPTION => 'No meta description',
            self::FILTER_NO_KEYWORD => 'No focus keyword',
            self::FILTER_NOINDEX => 'Marked noindex',
        ];
    }

    /**
     * @return list<ContentRow>
     */
    public function rows(string $filter = self::FILTER_ALL, string $search = ''): array
    {
        $rows = [];

        foreach ($this->registry->all() as $handle => $source) {
            foreach ($this->collect($handle, $source) as $row) {
                if ($this->matches($row, $filter, $search)) {
                    $rows[] = $row;
                }
            }
        }

        // Worst first: this list exists to be worked through, not browsed.
        usort($rows, static fn (array $a, array $b): int => ($a['seoScore'] ?? -1) <=> ($b['seoScore'] ?? -1));

        return $rows;
    }

    /**
     * Save a title and description straight from the list.
     *
     * The whole point of this screen is fixing two hundred missing descriptions
     * without opening two hundred editors, so the write happens here rather than
     * bouncing through the entry form.
     */
    public function save(string $modelType, string $modelId, ?string $title, ?string $description): void
    {
        $this->meta->upsert($modelType, $modelId, [
            'title' => $this->clean($title),
            'description' => $this->clean($description),
        ]);
    }

    /**
     * @return list<ContentRow>
     */
    private function collect(string $handle, SeoSubjectSource $source): array
    {
        $rows = [];
        $modelClass = $source->modelClass();

        try {
            $source->chunk(function (array $batch) use (&$rows, $handle, $modelClass): void {
                $subjects = array_values(array_filter(
                    $batch,
                    static fn (SeoSubject $subject): bool => $subject->url !== '',
                ));

                $overrides = $this->overridesFor($subjects, $modelClass);

                foreach ($subjects as $subject) {
                    $meta = $modelClass !== null && $subject->modelId !== null
                        ? ($overrides[$this->meta->key($modelClass, $subject->modelId)] ?? null)
                        : null;

                    $rows[] = $this->row($handle, $subject, $meta, $modelClass);
                }
            });
        } catch (Throwable) {
            // A faulty source is reported by the scan; this list shows what it can.
            return $rows;
        }

        return $rows;
    }

    /**
     * @param  class-string|null  $modelClass
     * @return ContentRow
     */
    private function row(string $handle, SeoSubject $subject, ?SeoMeta $meta, ?string $modelClass): array
    {
        $cache = $meta?->analysis_cache;
        $keywords = $meta->focus_keywords ?? [];

        return [
            'source' => $handle,
            'key' => $subject->key,
            'modelType' => $modelClass,
            'modelId' => $subject->modelId,
            'title' => $meta->title ?? $subject->title,
            'url' => $subject->url,
            'description' => $meta->description ?? null,
            'keyword' => $keywords[0] ?? null,
            'seoScore' => is_array($cache) && is_int($cache['seo_score'] ?? null) ? $cache['seo_score'] : null,
            'readabilityScore' => is_array($cache) && is_int($cache['readability_score'] ?? null) ? $cache['readability_score'] : null,
            'indexable' => $subject->indexable && ($meta === null || (bool) $meta->getAttribute('robots_index') !== false),
        ];
    }

    /**
     * @param  ContentRow  $row
     */
    private function matches(array $row, string $filter, string $search): bool
    {
        if ($search !== '' && ! str_contains(mb_strtolower((string) $row['title'].' '.$row['url']), mb_strtolower($search))) {
            return false;
        }

        return match ($filter) {
            self::FILTER_NEEDS_WORK => is_int($row['seoScore']) && $row['seoScore'] < AnalysisReport::NEEDS_WORK_BELOW,
            self::FILTER_NO_DESCRIPTION => trim((string) $row['description']) === '',
            self::FILTER_NO_KEYWORD => trim((string) $row['keyword']) === '',
            self::FILTER_NOINDEX => $row['indexable'] === false,
            default => true,
        };
    }

    /**
     * @param  list<SeoSubject>  $subjects
     * @param  class-string|null  $modelClass
     * @return array<string, SeoMeta>
     */
    private function overridesFor(array $subjects, ?string $modelClass): array
    {
        if ($modelClass === null) {
            return [];
        }

        $pairs = [];
        foreach ($subjects as $subject) {
            if ($subject->modelId !== null && $subject->modelId !== '') {
                $pairs[] = [$modelClass, $subject->modelId];
            }
        }

        return $pairs === [] ? [] : $this->meta->forMany($pairs);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
