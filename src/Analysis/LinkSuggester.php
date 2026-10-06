<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Which existing pages this draft should link to.
 *
 * Internal links are the only part of a site's link graph its owner fully
 * controls, and they are how authority moves from the pages that have it to the
 * pages that need it. Yoast charges for this; the graph and the vocabulary are
 * already here, so it costs a comparison.
 *
 * Candidates are scored on how much of their subject the draft is also about,
 * with cornerstone pages weighted up — the whole reason to mark a page
 * cornerstone is to say "send authority here". Pages the draft already links to
 * are dropped, because the suggestion is only useful if it is news.
 */
final class LinkSuggester
{
    /** A candidate must share at least this many terms to be worth suggesting. */
    private const MIN_OVERLAP = 2;

    public function __construct(
        private readonly SeoSourceRegistry $registry,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * @param  string  $draftText  The draft's plain text.
     * @param  list<string>  $alreadyLinked  Absolute URLs or paths the draft links to.
     * @return list<array{title: string, url: string, score: int, shared: list<string>, cornerstone: bool}>
     */
    public function for(string $draftText, array $alreadyLinked = [], ?string $excludeUrl = null, int $limit = 5): array
    {
        $terms = ProminentWords::terms($draftText, 25);

        if ($terms === []) {
            return [];
        }

        $linked = array_flip(array_map(
            static fn (string $url): string => rtrim((string) parse_url($url, PHP_URL_PATH), '/'),
            $alreadyLinked,
        ));

        $suggestions = [];

        foreach ($this->registry->all() as $source) {
            foreach ($this->candidates($source) as [$subject, $cornerstone]) {
                if ($subject->url === $excludeUrl || $subject->url === '') {
                    continue;
                }

                if (isset($linked[rtrim((string) parse_url($subject->url, PHP_URL_PATH), '/')])) {
                    continue;
                }

                $shared = $this->sharedTerms($terms, $subject);

                if (count($shared) < self::MIN_OVERLAP) {
                    continue;
                }

                $suggestions[] = [
                    'title' => $subject->title,
                    'url' => $subject->url,
                    // Cornerstone pages are what a site wants authority pointed
                    // at, so they outrank an equally relevant ordinary page.
                    'score' => count($shared) * ($cornerstone ? 2 : 1),
                    'shared' => $shared,
                    'cornerstone' => $cornerstone,
                ];
            }
        }

        usort($suggestions, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function sharedTerms(array $terms, SeoSubject $subject): array
    {
        $subjectTerms = ProminentWords::terms(
            $subject->title.' '.($subject->excerpt ?? '').' '.$subject->plainText,
            25,
        );

        return array_values(array_intersect($terms, $subjectTerms));
    }

    /**
     * Indexable subjects paired with whether they are cornerstone.
     *
     * @return list<array{0: SeoSubject, 1: bool}>
     */
    private function candidates(SeoSubjectSource $source): array
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

                    $rows[] = [$subject, (bool) ($meta->is_cornerstone ?? false)];
                }
            });
        } catch (Throwable) {
            // A failing source simply contributes no suggestions.
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
