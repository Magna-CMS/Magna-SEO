<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Analysis\RecordAnalyser;

/**
 * Translates between the SEO editor panel's form state and the magna_seo_meta
 * override for a content model. This is the testable core of the entry-form SEO
 * panel: the Filament components only hydrate from load() and persist through
 * save(), so the mapping and the write are verified independently of the panel's
 * render.
 */
final class EntrySeoState
{
    /** Keys shared by the form state and this mapper. */
    public const FIELDS = [
        'seo_title', 'seo_description', 'seo_canonical_url',
        'seo_robots_index', 'seo_robots_follow',
        'seo_og_title', 'seo_og_description', 'seo_twitter_card', 'seo_focus_keyword',
        'seo_is_cornerstone',
    ];

    public function __construct(
        private readonly SeoMetaRepository $repository,
        private readonly ?RecordAnalyser $analyser = null,
    ) {}

    /**
     * The form state for a record, defaulting to a blank override when none exists.
     *
     * @return array<string, mixed>
     */
    public function load(Model $record): array
    {
        $meta = $this->repository->forModel($record);

        if ($meta === null) {
            return [
                'seo_title' => null,
                'seo_description' => null,
                'seo_canonical_url' => null,
                'seo_robots_index' => true,
                'seo_robots_follow' => true,
                'seo_og_title' => null,
                'seo_og_description' => null,
                'seo_twitter_card' => null,
                'seo_focus_keyword' => null,
                'seo_is_cornerstone' => false,
            ];
        }

        $keywords = $meta->focus_keywords ?? [];

        return [
            'seo_title' => $meta->title,
            'seo_description' => $meta->description,
            'seo_canonical_url' => $meta->canonical_url,
            'seo_robots_index' => $meta->robots_index,
            'seo_robots_follow' => $meta->robots_follow,
            'seo_og_title' => $meta->og_title,
            'seo_og_description' => $meta->og_description,
            'seo_twitter_card' => $meta->twitter_card,
            'seo_focus_keyword' => $keywords[0] ?? null,
            'seo_is_cornerstone' => $meta->is_cornerstone,
        ];
    }

    /**
     * Persist the panel state as the record's override. A wholly-empty panel does
     * not create a row (an empty override is identical to none), but it does clear
     * an existing one.
     *
     * @param  array<string, mixed>  $state
     */
    public function save(Model $record, array $state): void
    {
        $keyword = trim((string) ($state['seo_focus_keyword'] ?? ''));
        $index = (bool) ($state['seo_robots_index'] ?? true);
        $follow = (bool) ($state['seo_robots_follow'] ?? true);
        $cornerstone = (bool) ($state['seo_is_cornerstone'] ?? false);

        $attributes = [
            'title' => $this->clean($state, 'seo_title'),
            'description' => $this->clean($state, 'seo_description'),
            'canonical_url' => $this->clean($state, 'seo_canonical_url'),
            'robots_index' => $index,
            'robots_follow' => $follow,
            'og_title' => $this->clean($state, 'seo_og_title'),
            'og_description' => $this->clean($state, 'seo_og_description'),
            'twitter_card' => $this->clean($state, 'seo_twitter_card'),
            'focus_keywords' => $keyword !== '' ? [$keyword] : null,
            'is_cornerstone' => $cornerstone,
        ];

        $isBlank = $index && $follow && ! $cornerstone
            && $attributes['title'] === null
            && $attributes['description'] === null
            && $attributes['canonical_url'] === null
            && $attributes['og_title'] === null
            && $attributes['og_description'] === null
            && $attributes['twitter_card'] === null
            && $attributes['focus_keywords'] === null;

        if ($isBlank && $this->repository->forModel($record) === null) {
            return;
        }

        // Analysis runs here, on save, so the render path never pays for it and
        // reopening the editor shows the last result immediately.
        if ($this->analyser !== null) {
            $report = $this->analyser->analyse(
                $record,
                $keyword,
                $attributes['title'],
                $attributes['description'],
            );

            $attributes['analysis_cache'] = $report->toArray();
            $attributes['analysis_computed_at'] = now();
        }

        $this->repository->upsert($record->getMorphClass(), (string) $record->getKey(), $attributes);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function clean(array $state, string $key): ?string
    {
        $value = trim((string) ($state[$key] ?? ''));

        return $value !== '' ? $value : null;
    }
}
