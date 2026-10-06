<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Throwable;

/**
 * Computes a subject's SEO and readability scores and stores them on its meta row.
 *
 * This exists because the scores had readers and no writer. The content list, the
 * SEO and readability badges in the entry table, the "needs work" filter and the
 * weak-publish warning all read `analysis_cache`, and nothing ever populated it —
 * so on any site with existing content every score read "—" for ever, and the
 * filter that is supposed to surface the worst pages matched nothing at all.
 *
 * Scores are advice for an author, never part of a rendered response, so this is
 * called when content is scanned or saved and never while serving a page.
 */
final class AnalysisCache
{
    public function __construct(
        private readonly ContentAnalyser $analyser,
        private readonly MetaResolver $resolver,
        private readonly SeoMetaRepository $meta = new SeoMetaRepository,
    ) {}

    /**
     * Recompute and store one subject's scores, returning the report.
     *
     * Returns null when the subject has no model to attach to — meta is keyed by
     * model class and id, so a source that is not Eloquent-backed has nowhere to
     * keep this.
     */
    public function refresh(SeoSubject $subject, ?string $modelClass): ?AnalysisReport
    {
        if ($modelClass === null || $subject->modelId === null) {
            return null;
        }

        $existing = $this->meta->for($modelClass, $subject->modelId);

        // A page with no body has nothing to score. Analysing it anyway returns
        // a number built from checks that cannot fail on emptiness — an empty
        // draft stored 38/100, which reads as "analysed and mediocre" rather
        // than "not written yet", on every screen that shows it. The scan
        // reports the real problem as thin content; a fabricated score would
        // only disguise it.
        if (trim($subject->plainText) === '') {
            $this->meta->upsert($modelClass, $subject->modelId, [
                'analysis_cache' => null,
                'analysis_computed_at' => null,
            ]);

            return null;
        }

        $report = $this->analyse($subject, $existing);

        // The exact shape the save path writes, deliberately. Two writers with
        // two shapes drift: a slimmer payload here would have dropped `score`
        // and `results`, silently disabling the weak-publish warning and the
        // per-check list the moment a scan ran after a save.
        $this->meta->upsert($modelClass, $subject->modelId, [
            'analysis_cache' => $report->toArray(),
            'analysis_computed_at' => now(),
        ]);

        return $report;
    }

    /**
     * Recompute every indexable subject in every registered source.
     *
     * @param  callable(SeoSubject, AnalysisReport|null): void|null  $each  Progress callback.
     */
    public function refreshAll(iterable $sources, ?callable $each = null): int
    {
        $count = 0;

        foreach ($sources as $source) {
            $modelClass = $source->modelClass();

            $source->chunk(function (array $batch) use ($modelClass, $each, &$count): void {
                foreach ($batch as $subject) {
                    try {
                        $report = $this->refresh($subject, $modelClass);
                    } catch (Throwable) {
                        // One unanalysable page must not stop the backfill; the
                        // scan reports content problems separately.
                        $report = null;
                    }

                    $count++;

                    if ($each !== null) {
                        $each($subject, $report);
                    }
                }
            });
        }

        return $count;
    }

    private function analyse(SeoSubject $subject, ?SeoMeta $meta): AnalysisReport
    {
        $head = $this->resolver->resolve($subject, $meta, SeoSettings::get());

        $keywords = $meta?->focus_keywords ?? [];

        return $this->analyser->analyse(AnalysisInput::forSubject(
            $subject,
            $head->metaNames['description'] ?? '',
            is_array($keywords) ? (string) ($keywords[0] ?? '') : '',
        ));
    }
}
