{{--
    Live SEO panel.

    Two scores, each with a colour AND a word: a coloured ring on its own is
    unreadable to the roughly one man in twelve with a colour-vision deficiency,
    and unreadable is not a design choice.

    Failing checks sort to the top of each list, because an author scanning this
    panel is looking for what to fix, not for confirmation of what already works.
--}}
@php
    use Magna\Seo\Support\FixInstructions;

    $hasContent = $this->hasContent();

    // Nothing is analysed until there is something to analyse, so the expensive
    // half of this view is not built for a blank draft either.
    $categories = $hasContent ? $this->categories() : [];

    $ring = fn (string $band): string => match ($band) {
        'bad' => 'text-danger-600 dark:text-danger-400',
        'ok' => 'text-warning-600 dark:text-warning-400',
        default => 'text-success-600 dark:text-success-400',
    };

    $dot = fn (string $status): string => match ($status) {
        'bad' => 'bg-danger-500',
        'ok' => 'bg-warning-500',
        default => 'bg-success-500',
    };

    $rank = ['bad' => 0, 'ok' => 1, 'good' => 2];

    $prominent = $hasContent ? $this->prominentWords() : [];
@endphp

<div
    class="space-y-5"
    x-data
    x-on:seo-content-changed.window="$wire.syncFromForm($event.detail)"
>
    @unless ($hasContent)
        {{-- The same two cards, greyed. An empty box that later jumps into
             existence is more disorienting than a placeholder in the place the
             score will occupy. --}}
        <div class="grid grid-cols-2 gap-3">
            @foreach (['SEO', 'Readability'] as $label)
                <div class="rounded-xl border border-dashed border-gray-200 p-4 text-center dark:border-gray-700">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>

                    <div class="mt-1 text-3xl font-bold tabular-nums text-gray-300 dark:text-gray-600">
                        —<span class="text-sm font-medium text-gray-400">/100</span>
                    </div>

                    <div class="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-gray-400">
                        <span class="h-2 w-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                        Not analysed
                    </div>
                </div>
            @endforeach
        </div>

        <p class="max-w-prose text-xs text-gray-500 dark:text-gray-400">
            Write something and the scores appear. Grading an empty page would mean
            marking down an article nobody has started.
        </p>
    @endunless

    @if ($hasContent)
    <div class="grid grid-cols-2 gap-3">
        @foreach ($categories as $category)
            <div class="rounded-xl border border-gray-200 p-4 text-center dark:border-gray-700">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $category['label'] }}</div>

                <div class="mt-1 text-3xl font-bold tabular-nums {{ $ring($category['band']) }}">
                    {{ $category['score'] }}<span class="text-sm font-medium text-gray-400">/100</span>
                </div>

                <div class="mt-1 inline-flex items-center gap-1.5 text-xs font-medium {{ $ring($category['band']) }}">
                    <span class="h-2 w-2 rounded-full {{ $dot($category['band']) }}"></span>
                    {{ $category['bandLabel'] }}
                </div>
            </div>
        @endforeach
    </div>

    @if ($prominent !== [])
        <div>
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">What this page is about</h4>
            <p class="text-xs text-gray-400">
                The words that dominate the text. If your focus keyword is not among them, the page is about something else.
            </p>

            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($prominent as $word)
                    <span @class([
                        'rounded-md px-2 py-0.5 text-xs',
                        'bg-primary-100 font-medium text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' => $word['isKeyword'],
                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => ! $word['isKeyword'],
                    ])>
                        {{ $word['word'] }} <span class="opacity-60">{{ $word['count'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @foreach ($categories as $key => $category)
        @php
            $results = collect($category['results'])
                ->sortBy(fn ($result) => $rank[$result->status->value] ?? 3)
                ->values();
            $failing = $results->where(fn ($result) => $result->status->value !== 'good')->count();
        @endphp

        <div>
            <div class="flex items-baseline justify-between">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $category['label'] }}</h4>
                <span class="text-xs text-gray-400">
                    {{ $failing === 0 ? 'All checks pass' : $failing.' to improve' }}
                </span>
            </div>

            <ul class="mt-2 space-y-2">
                @foreach ($results as $result)
                    @php
                        // Block form throughout this file, never the inline
                        // directive: an inline opener placed before a later
                        // block makes Blade pair it with that block's closing
                        // tag and swallow everything between as raw PHP.
                        $fix = FixInstructions::for($result->check);
                    @endphp

                    <li class="flex gap-2 text-xs">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $dot($result->status->value) }}"></span>

                        <div class="min-w-0">
                            <p class="text-gray-700 dark:text-gray-200">{{ $result->message }}</p>

                            @if ($fix && $result->status->value !== 'good')
                                <details class="mt-0.5">
                                    <summary class="cursor-pointer text-primary-600 dark:text-primary-400">How to fix</summary>
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $fix }}</p>
                                </details>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
    @endif
</div>
