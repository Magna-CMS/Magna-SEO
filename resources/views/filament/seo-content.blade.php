@php
    $rows = $this->rows();

    $tone = fn (?int $score): string => match (true) {
        $score === null => 'bg-gray-300 dark:bg-gray-600',
        $score < 41 => 'bg-danger-500',
        $score <= 70 => 'bg-warning-500',
        default => 'bg-success-500',
    };

    $label = fn (?int $score): string => $score === null
        ? 'Not analysed'
        : \Magna\Seo\Analysis\AnalysisReport::bandLabel($score);
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-1">
            @foreach ($this->filterOptions() as $value => $text)
                <button type="button" wire:click="$set('filter', '{{ $value }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-xs font-medium transition',
                        'bg-primary-600 text-white' => $filter === $value,
                        'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300' => $filter !== $value,
                    ])>
                    {{ $text }}
                </button>
            @endforeach
        </div>

        {{-- Sized by a plugin-owned class, not `w-full sm:w-64`. The plugin's
             stylesheet sits in the `components` layer so it can never beat a
             utility the host does ship, and `w-full` is one — the field took the
             whole row and squeezed the filters onto two lines. A class no host
             build defines has nothing to lose to. --}}
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search titles and URLs…"
            class="magna-seo-search rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-white/5" />
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        {{ count($rows) }} {{ \Illuminate\Support\Str::plural("page", count($rows)) }}. Scores come from the last time each page was saved — open a page to re-analyse it.
    </p>

    @if ($rows === [])
        <div class="mt-6 rounded-xl border border-dashed p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            Nothing matches this filter.
        </div>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($rows as $row)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $row['title'] }}</div>
                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer"
                               class="truncate text-xs text-gray-400 hover:underline">{{ $row['url'] }}</a>
                        </div>

                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            @unless ($row['indexable'])
                                <span class="rounded-md bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">noindex</span>
                            @endunless

                            <span class="inline-flex items-center gap-1.5" title="SEO: {{ $label($row['seoScore']) }}">
                                <span class="h-2.5 w-2.5 rounded-full {{ $tone($row['seoScore']) }}"></span>
                                SEO {{ $row['seoScore'] ?? '—' }}
                            </span>

                            <span class="inline-flex items-center gap-1.5" title="Readability: {{ $label($row['readabilityScore']) }}">
                                <span class="h-2.5 w-2.5 rounded-full {{ $tone($row['readabilityScore']) }}"></span>
                                Read {{ $row['readabilityScore'] ?? '—' }}
                            </span>
                        </div>
                    </div>

                    @if ($row['modelType'] && $row['modelId'])
                        <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_2fr_auto]">
                            <input type="text"
                                wire:model="edits.{{ $row['modelId'] }}.title"
                                value="{{ $row['title'] }}"
                                placeholder="SEO title"
                                class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-white/5" />

                            <input type="text"
                                wire:model="edits.{{ $row['modelId'] }}.description"
                                value="{{ $row['description'] }}"
                                placeholder="Meta description"
                                @class([
                                    'rounded-lg text-xs dark:bg-white/5',
                                    'border-danger-400 dark:border-danger-500/50' => trim((string) $row['description']) === '',
                                    'border-gray-300 dark:border-gray-700' => trim((string) $row['description']) !== '',
                                ]) />

                            <button type="button"
                                wire:click="saveRow('{{ addslashes($row['modelType']) }}', '{{ $row['modelId'] }}')"
                                class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-500">
                                Save
                            </button>
                        </div>
                    @else
                        <p class="mt-2 text-xs text-gray-400">
                            This source has no editable meta record; edit it where the content lives.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
