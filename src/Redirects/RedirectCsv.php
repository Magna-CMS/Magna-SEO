<?php

declare(strict_types=1);

namespace Magna\Seo\Redirects;

use Magna\Seo\Models\SeoRedirect;

/**
 * CSV import and export for redirect rules — the format every other SEO tool
 * exports, and the only practical way to move thousands of rules off a legacy
 * site.
 *
 * Import is validating and non-destructive: a malformed row is reported and
 * skipped rather than aborting the batch, and an existing rule for the same
 * source is updated in place instead of duplicated.
 */
final class RedirectCsv
{
    private const HEADER = ['source', 'target', 'status', 'match_type', 'preserve_query', 'is_active', 'notes'];

    /**
     * @param  list<array<int, string>>  $rows  Parsed CSV rows, header included.
     * @return array{imported: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(array $rows): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $columns = $this->columns($rows[0] ?? []);

        foreach ($rows as $index => $row) {
            if ($index === 0 && $this->looksLikeHeader($row)) {
                continue;
            }

            $line = $index + 1;
            $source = trim($row[$columns['source']] ?? '');
            $target = trim($row[$columns['target']] ?? '');
            $status = (int) ($row[$columns['status']] ?? 301);
            $matchType = $this->cell($row, $columns['match_type']) !== ''
                ? $this->cell($row, $columns['match_type'])
                : MatchType::Exact->value;

            if ($source === '') {
                $errors[] = "Line {$line}: source is empty.";
                $skipped++;

                continue;
            }

            if (MatchType::tryFrom($matchType) === null) {
                $errors[] = "Line {$line}: unknown match type \"{$matchType}\".";
                $skipped++;

                continue;
            }

            if (! in_array($status, [301, 302, 307, 308, 410], true)) {
                $errors[] = "Line {$line}: status {$status} is not a redirect or gone status.";
                $skipped++;

                continue;
            }

            if ($status !== 410 && $target === '') {
                $errors[] = "Line {$line}: a {$status} needs a target.";
                $skipped++;

                continue;
            }

            $normalised = $matchType === MatchType::Exact->value
                ? RedirectStore::normaliseSource($source)
                : $source;

            $existing = SeoRedirect::query()
                ->where('match_type', $matchType)
                ->where('source_path', $normalised)
                ->first();

            $attributes = [
                'source_path' => $source,
                'match_type' => $matchType,
                'target' => $status === 410 ? null : $target,
                'status_code' => $status,
                'preserve_query' => $this->flag($this->cell($row, $columns['preserve_query']), true),
                'is_active' => $this->flag($this->cell($row, $columns['is_active']), true),
                'notes' => $this->cell($row, $columns['notes']) !== ''
                    ? mb_substr($this->cell($row, $columns['notes']), 0, 500)
                    : null,
            ];

            if ($existing instanceof SeoRedirect) {
                $existing->fill($attributes)->save();
                $updated++;

                continue;
            }

            SeoRedirect::query()->create($attributes);
            $imported++;
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Every rule as CSV rows, header first.
     *
     * @return list<list<string>>
     */
    public function export(): array
    {
        $rows = [self::HEADER];

        SeoRedirect::query()->orderBy('id')->each(function (SeoRedirect $redirect) use (&$rows): void {
            $rows[] = [
                $redirect->source_path,
                (string) $redirect->target,
                (string) $redirect->status_code,
                $redirect->match_type,
                $redirect->preserve_query ? '1' : '0',
                $redirect->is_active ? '1' : '0',
                (string) $redirect->notes,
            ];
        });

        return $rows;
    }

    /**
     * A leading cell naming a known source column marks a header row; anything
     * else is data, so a file exported without headers still imports.
     *
     * @param  array<int, string>  $row
     */
    private function looksLikeHeader(array $row): bool
    {
        return in_array(strtolower(trim($row[0] ?? '')), ['source', 'origin', 'sources', 'url', 'old url'], true);
    }

    /**
     * Which column holds which field.
     *
     * Yoast Premium and Rank Math both export redirects as CSV, and neither uses
     * this plugin's column order — Yoast leads with "origin", Rank Math with
     * "sources". Recognising their headers means a migration is a file drop
     * rather than a spreadsheet exercise; anything unrecognised falls back to
     * this plugin's own layout.
     *
     * @param  array<int, string>  $header
     * @return array{source: int, target: int, status: int, match_type: int|null, preserve_query: int|null, is_active: int|null, notes: int|null}
     */
    private function columns(array $header): array
    {
        $names = array_map(static fn (string $cell): string => strtolower(trim($cell)), $header);

        $find = static function (array $candidates) use ($names): ?int {
            foreach ($candidates as $candidate) {
                $position = array_search($candidate, $names, true);

                if ($position !== false) {
                    return (int) $position;
                }
            }

            return null;
        };

        $ours = $names !== [] && in_array('source', $names, true);

        return [
            'source' => $find(['source', 'origin', 'sources', 'old url', 'url']) ?? 0,
            'target' => $find(['target', 'url_to', 'destination', 'new url']) ?? 1,
            // Ordered most-specific first: Rank Math has both "header_code" (the
            // status) and "status" (active/inactive), so a plain "status" lookup
            // would read the wrong column.
            'status' => $find(['header_code', 'status_code', 'code', 'type', 'status']) ?? 2,
            // The remaining columns are this plugin's own. A foreign export has no
            // equivalent, so those rows take the defaults rather than reading
            // whatever happens to sit in that position.
            'match_type' => $ours || $names === [] ? 3 : null,
            'preserve_query' => $ours || $names === [] ? 4 : null,
            'is_active' => $ours || $names === [] ? 5 : null,
            'notes' => $ours || $names === [] ? 6 : null,
        ];
    }

    /**
     * One cell, or '' when the column is absent from this file's layout.
     *
     * @param  array<int, string>  $row
     */
    private function cell(array $row, ?int $index): string
    {
        return $index === null ? '' : trim($row[$index] ?? '');
    }

    private function flag(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));

        return match ($value) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => $default,
        };
    }
}
