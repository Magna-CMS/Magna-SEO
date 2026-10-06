<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Which pages have been optimised for anything, and which are competing with
 * each other.
 *
 * The content analysis is per-page and only runs when somebody opens the panel,
 * so a site can be full of pages nobody ever set a focus keyword on and nothing
 * says so. Two questions matter site-wide:
 *
 * - **Uncovered** — indexable pages with no focus keyword. Nobody decided what
 *   these pages are for.
 * - **Cannibalised** — two or more pages targeting the same keyword. They
 *   compete with each other, split whatever authority the topic has earned, and
 *   search engines pick one more or less arbitrarily.
 */
final class KeywordCoverage
{
    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     covered: int,
     *     uncovered: list<array{source: string, title: string, url: string}>,
     *     cannibalised: array<string, list<array{source: string, title: string, url: string}>>
     * }
     */
    public function report(): array
    {
        $uncovered = [];
        $byKeyword = [];
        $total = 0;
        $covered = 0;

        foreach ($this->registry->all() as $handle => $source) {
            foreach ($this->subjectsOf($handle, $source) as [$subject, $keywords]) {
                $total++;

                $row = [
                    'source' => $handle,
                    'title' => $subject->title,
                    'url' => $subject->url,
                ];

                if ($keywords === []) {
                    $uncovered[] = $row;

                    continue;
                }

                $covered++;

                // Only the focus keyword competes; secondary ones are supporting
                // terms and are expected to repeat across pages.
                $byKeyword[mb_strtolower($keywords[0])][] = $row;
            }
        }

        $cannibalised = array_filter($byKeyword, static fn (array $rows): bool => count($rows) > 1);

        return [
            'total' => $total,
            'covered' => $covered,
            'uncovered' => $uncovered,
            'cannibalised' => $cannibalised,
        ];
    }

    /**
     * Indexable subjects of one source paired with their focus keywords, with
     * overrides bulk-loaded per chunk.
     *
     * @return list<array{0: SeoSubject, 1: list<string>}>
     */
    private function subjectsOf(string $handle, SeoSubjectSource $source): array
    {
        $rows = [];
        $modelClass = $source->modelClass();

        try {
            $source->chunk(function (array $batch) use (&$rows, $modelClass): void {
                $subjects = array_values(array_filter(
                    $batch,
                    static fn (SeoSubject $subject): bool => $subject->indexable && $subject->url !== '',
                ));

                $overrides = $this->overridesFor($subjects, $modelClass);

                foreach ($subjects as $subject) {
                    $meta = $modelClass !== null && $subject->modelId !== null
                        ? ($overrides[$this->meta->key($modelClass, $subject->modelId)] ?? null)
                        : null;

                    $keywords = $meta === null ? [] : ($meta->focus_keywords ?? []);

                    $rows[] = [$subject, array_values(array_filter(
                        $keywords,
                        static fn (string $keyword): bool => trim($keyword) !== '',
                    ))];
                }
            });
        } catch (Throwable) {
            // A faulty source is already reported by the scan; coverage simply
            // reports on what it can read.
            return $rows;
        }

        return $rows;
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
}
