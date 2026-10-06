<?php

declare(strict_types=1);

namespace Magna\Seo\Filament;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Models\SeoMeta;

/**
 * SEO and readability at a glance in the entry list, and filters for the
 * questions people actually ask of it.
 *
 * Everything here reads the analysis cached when each entry was last saved.
 * Nothing is computed per row: a table renders a page of entries at a time, and
 * a column that analyses on render turns a list into a timeout.
 *
 * The filters are subqueries against magna_seo_meta rather than joins, so they
 * compose with whatever else the table is already filtering by and cannot
 * duplicate rows when an entry somehow has more than one meta record.
 */
final class EntrySeoColumns
{
    /**
     * @return list<mixed>
     */
    public static function columns(): array
    {
        return [
            self::scoreColumn('seo_score', 'SEO'),
            self::scoreColumn('readability_score', 'Read'),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function filters(): array
    {
        return [
            Filter::make('seo_needs_work')
                ->label('SEO needs work')
                ->query(fn (Builder $query): Builder => $query->whereIn('id', self::idsScoringBelow('seo_score'))),

            Filter::make('seo_missing_description')
                ->label('No meta description')
                ->query(fn (Builder $query): Builder => $query->whereNotIn('id', self::idsWithDescription())),

            Filter::make('seo_noindex')
                ->label('Marked noindex')
                ->query(fn (Builder $query): Builder => $query->whereIn('id', self::idsMarkedNoindex())),
        ];
    }

    /**
     * A traffic light with its number. The word is in the tooltip and the colour
     * carries the glance value, but the number is always shown — a coloured dot
     * alone is unreadable to roughly one man in twelve.
     */
    private static function scoreColumn(string $key, string $label): TextColumn
    {
        return TextColumn::make('seo_'.$key)
            ->label($label)
            ->badge()
            ->state(fn ($record): string => self::scoreOf($record, $key) === null
                ? '—'
                : (string) self::scoreOf($record, $key))
            ->color(function ($record) use ($key): string {
                $score = self::scoreOf($record, $key);

                return match (true) {
                    $score === null => 'gray',
                    $score < AnalysisReport::NEEDS_WORK_BELOW => 'danger',
                    $score <= AnalysisReport::GOOD_ABOVE => 'warning',
                    default => 'success',
                };
            })
            ->tooltip(function ($record) use ($key): string {
                $score = self::scoreOf($record, $key);

                return $score === null
                    ? 'Not analysed yet — open and save the entry.'
                    : AnalysisReport::bandLabel($score);
            });
    }

    /**
     * Cached analysis per morph type, loaded once for the whole table render.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $cache = [];

    /**
     * The cached score for one record, or null when it has never been analysed.
     *
     * Public because it is the whole behaviour of these columns: the Filament
     * wrapper around it cannot be exercised without booting a panel, so this is
     * where the tests attach.
     */
    public static function scoreOf(mixed $record, string $key): ?int
    {
        // The table hands rows through as mixed; only Eloquent records can carry
        // an SEO override, and anything else simply has no score to show.
        if (! $record instanceof Model) {
            return null;
        }

        $type = $record->getMorphClass();

        // One query per morph type per request, not one per row. A table renders
        // a page of entries at a time and a per-row query is how a list becomes
        // a timeout.
        if (! isset(self::$cache[$type])) {
            self::$cache[$type] = SeoMeta::query()
                ->where('seoable_type', $type)
                ->whereNotNull('analysis_cache')
                ->get(['seoable_id', 'analysis_cache'])
                ->mapWithKeys(static fn (SeoMeta $meta): array => [
                    (string) $meta->getAttribute('seoable_id') => is_array($meta->analysis_cache)
                        ? $meta->analysis_cache
                        : [],
                ])
                ->all();
        }

        $analysis = self::$cache[$type][(string) $record->getKey()] ?? null;

        return is_array($analysis) && is_int($analysis[$key] ?? null) ? $analysis[$key] : null;
    }

    /**
     * Drop the memo — used by tests, and by any caller that changes analysis and
     * re-renders inside the same request.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @return Builder<SeoMeta>
     */
    private static function metaIds(): Builder
    {
        return SeoMeta::query()->select('seoable_id');
    }

    /**
     * @return Builder<SeoMeta>
     */
    private static function idsScoringBelow(string $key): Builder
    {
        // JSON containment varies by driver; the cached score is small enough to
        // filter in SQL with a LIKE against the encoded value only for the
        // clearly-bad band, so this stays portable across sqlite and MySQL.
        $patterns = [];
        for ($score = 0; $score < AnalysisReport::NEEDS_WORK_BELOW; $score++) {
            $patterns[] = '%"'.$key.'":'.$score.'%';
        }

        return self::metaIds()->where(function (Builder $query) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $query->orWhere('analysis_cache', 'like', $pattern);
            }
        });
    }

    /**
     * @return Builder<SeoMeta>
     */
    private static function idsWithDescription(): Builder
    {
        return self::metaIds()->whereNotNull('description')->where('description', '!=', '');
    }

    /**
     * @return Builder<SeoMeta>
     */
    private static function idsMarkedNoindex(): Builder
    {
        return self::metaIds()->where('robots_index', false);
    }
}
