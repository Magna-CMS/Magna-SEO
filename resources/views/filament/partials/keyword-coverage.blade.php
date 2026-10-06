{{--
    Keyword coverage: pages nobody set a purpose for, and pages competing with
    each other for the same one. Shared by the dashboard and the Health page.
    Expects $coverage from KeywordCoverage::report().

    Every row is a destination. The previous version listed titles and raw URLs
    as plain text with a dead "and 12 more" at the bottom — it told an editor
    what was wrong and gave them nowhere to go, which is a report, not a tool.
--}}
@php
    $contentUrl = \Magna\Seo\Support\PanelUrl::for(\Magna\Seo\Filament\Pages\SeoContentPage::class);
    $keywordUrl = $contentUrl ? $contentUrl.'?filter=no-keyword' : null;

    $uncovered = $coverage['uncovered'];
    $cannibalised = $coverage['cannibalised'];

    $percent = $coverage['total'] > 0
        ? (int) round($coverage['covered'] / $coverage['total'] * 100)
        : 100;

    // Coverage is a ratio, and a ratio deserves a colour that means something:
    // most sites never reach 100%, so "good" starts where the majority of pages
    // have a stated purpose rather than at perfection.
    $bar = match (true) {
        $percent >= 80 => 'bg-success-500',
        $percent >= 40 => 'bg-warning-500',
        default => 'bg-danger-500',
    };
@endphp

@if ($coverage['total'] > 0)
    <div class="mt-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Keyword coverage</h3>
                <p class="mt-1 max-w-prose text-sm text-gray-500 dark:text-gray-400">
                    A page with no focus keyword is a page nobody decided the purpose of — nothing
                    can be scored against it, and it competes with your other pages by accident.
                </p>
            </div>

            @if ($keywordUrl && $uncovered !== [])
                <a href="{{ $keywordUrl }}"
                   class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500">
                    Set keywords ({{ count($uncovered) }})
                </a>
            @endif
        </div>

        {{-- The ratio, as a ratio. It was prose before, which made the single
             most important number here the hardest thing on the card to find. --}}
        <div class="mt-4 rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ $coverage['covered'] }} of {{ $coverage['total'] }}
                    {{ \Illuminate\Support\Str::plural('page', $coverage['total']) }} have one
                </span>
                <span class="text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $percent }}%</span>
            </div>

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-full {{ $bar }} transition-all" style="width: {{ $percent }}%"></div>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            {{-- Pages with no keyword --}}
            <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                <div class="flex items-baseline justify-between gap-2">
                    <h4 class="text-sm font-medium text-gray-950 dark:text-white">No focus keyword</h4>
                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        {{ count($uncovered) }}
                    </span>
                </div>

                @if ($uncovered === [])
                    <p class="mt-3 text-sm text-success-600 dark:text-success-400">Every page has one.</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_slice($uncovered, 0, 8) as $page)
                            <li class="flex items-center justify-between gap-3 py-2 first:pt-0">
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-gray-950 dark:text-white">{{ $page['title'] }}</div>
                                    <div class="truncate text-xs text-gray-400">{{ $page['url'] }}</div>
                                </div>

                                <a href="{{ $page['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    Open
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    @if (count($uncovered) > 8)
                        {{-- A real destination, not a dead sentence: the content
                             list can set a keyword on every one of them. --}}
                        <a href="{{ $keywordUrl ?? '#' }}"
                           class="mt-3 inline-block text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                            See all {{ count($uncovered) }} on the content list
                        </a>
                    @endif
                @endif
            </div>

            {{-- Pages competing with each other --}}
            <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                <div class="flex items-baseline justify-between gap-2">
                    <h4 class="text-sm font-medium text-gray-950 dark:text-white">Competing for the same keyword</h4>
                    <span @class([
                        'rounded-md px-2 py-0.5 text-xs font-medium',
                        'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300' => $cannibalised !== [],
                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $cannibalised === [],
                    ])>
                        {{ count($cannibalised) }}
                    </span>
                </div>

                @if ($cannibalised === [])
                    <p class="mt-3 text-sm text-success-600 dark:text-success-400">No two pages target the same keyword.</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_slice($cannibalised, 0, 5, true) as $keyword => $pages)
                            <li class="py-2 first:pt-0">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <span class="rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                        {{ $keyword }}
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        {{ count($pages) }} pages
                                    </span>
                                </div>

                                {{-- Listed, not comma-joined: these are the pages
                                     you have to choose between, so each one needs
                                     to be openable. --}}
                                <ul class="mt-1 space-y-1">
                                    @foreach ($pages as $page)
                                        <li class="truncate text-xs">
                                            <a href="{{ $page['url'] }}" target="_blank" rel="noopener noreferrer"
                                               class="text-gray-600 hover:underline dark:text-gray-300">
                                                {{ $page['title'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>

                    @if (count($cannibalised) > 5)
                        <p class="mt-2 text-xs text-gray-400">
                            and {{ count($cannibalised) - 5 }} more {{ \Illuminate\Support\Str::plural('keyword', count($cannibalised) - 5) }}.
                        </p>
                    @endif

                    <p class="mt-3 max-w-prose text-xs text-gray-500 dark:text-gray-400">
                        Pick one page to own each keyword and point the others at it, or give them
                        keywords of their own. Two pages chasing one phrase split the ranking between them.
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif
