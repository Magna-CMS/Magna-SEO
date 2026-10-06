{{--
    SEO dashboard.

    Theme tokens throughout, so it reads correctly in light and dark. Every
    figure states its window; a number with no period attached cannot be
    compared to anything.
--}}
@php
    use Magna\Seo\Filament\Pages\SeoHealthPage;
    use Magna\Seo\Filament\Pages\SeoSettingsPage;
    use Magna\Seo\Filament\Pages\SeoSetupPage;
    use Magna\Seo\Support\PanelUrl;

    $data = $this->summary();

    // Resolved defensively: one screen linking to another must never be able to
    // take the first one down when that route is not registered.
    $settingsUrl = PanelUrl::for(SeoSettingsPage::class);
    $healthUrl = PanelUrl::for(SeoHealthPage::class);
    $setupUrl = PanelUrl::for(SeoSetupPage::class);
    $setup = $this->setup();
    $queue = $this->queue();
    $byPage = $this->issuesByPage();
    $fixRoute = app(\Magna\Seo\Support\FixRoute::class);
    $health = $data['health'];
    $issues = $data['issues'];
    $search = $data['search'];

    $tone = fn (?int $score): string => match (true) {
        $score === null => 'text-gray-400',
        $score < 41 => 'text-danger-600 dark:text-danger-400',
        $score <= 70 => 'text-warning-600 dark:text-warning-400',
        default => 'text-success-600 dark:text-success-400',
    };

    $delta = function (?float $change, bool $higherIsBetter = true): string {
        if ($change === null || abs($change) < 0.05) {
            return '';
        }

        $good = $higherIsBetter ? $change > 0 : $change < 0;
        $arrow = $change > 0 ? '+' : '';

        return '<span class="text-xs font-medium '
            .($good ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400')
            .'">'.$arrow.round($change, 1).'</span>';
    };
@endphp

<x-filament-panels::page>
    {{-- SETUP: first, and only while there is something to finish. A half-built
         install cannot be judged by the numbers below it. --}}
    @if ($setup)
        @include('seo::filament.partials.setup-banner', ['setup' => $setup, 'setupUrl' => $setupUrl])
    @endif

    {{-- A stalled queue silently freezes scheduled scans and every integration,
         while the numbers below carry on looking current. Worth interrupting. --}}
    @if ($queue)
        @include('seo::filament.partials.queue-warning', ['queue' => $queue])
    @endif

    {{-- KPI row --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Site health</div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-bold tabular-nums {{ $tone($health['score']) }}">
                    {{ $health['score'] ?? '—' }}<span class="text-sm text-gray-400">/100</span>
                </span>
                {!! $delta($health['change']) !!}
            </div>
            <div class="mt-1 text-xs text-gray-400">
                {{ $health['scannedAt'] ? 'Scanned '.$health['scannedAt'] : 'No scan yet' }}
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Organic clicks · last 28 days</div>
            @if ($search['connected'] && $search['totals'])
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format($search['totals']['clicks']) }}
                    </span>
                    {!! $delta($search['totals']['clicks'] - $search['totals']['previous']['clicks']) !!}
                </div>
                <div class="mt-1 text-xs text-gray-400">
                    {{ number_format($search['totals']['impressions']) }} impressions
                </div>
            @else
                <div class="mt-3 text-sm text-gray-400">Not connected</div>
                @if ($settingsUrl)
                    <a href="{{ $settingsUrl }}"
                       class="mt-1 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400">
                        Connect Search Console
                    </a>
                @endif
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Average position</div>
            @if ($search['connected'] && $search['totals'])
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format($search['totals']['position'], 1) }}
                    </span>
                    {{-- A lower position number is better, so the arrow is inverted. --}}
                    {!! $delta($search['totals']['position'] - $search['totals']['previous']['position'], false) !!}
                </div>
                <div class="mt-1 text-xs text-gray-400">Across every query, last 28 days</div>
            @else
                <div class="mt-3 text-sm text-gray-400">Not connected</div>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Critical issues</div>
            <div class="mt-3 text-3xl font-bold tabular-nums {{ $issues['error'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                {{ $issues['error'] }}
            </div>
            <div class="mt-1 text-xs text-gray-400">
                {{ $issues['warning'] }} {{ Str::plural('warning', $issues['warning']) }}
                · {{ $data['notFound'] }} dead {{ Str::plural('URL', $data['notFound']) }} being hit
            </div>
        </div>
    </div>
    {{-- ACT: the single most valuable thing to do, directly under the numbers.
         Someone opening this dashboard has come to fix something, so the one
         recommendation stays above everything else. --}}
    {{-- What to do next --}}
    @if ($issues['topCheck'])
        <div class="mt-6 rounded-2xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-500/20 dark:bg-primary-500/10">
            <div class="text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">Start here</div>
            <p class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                {{ $issues['topCheck']['label'] }} —
                {{ $issues['topCheck']['count'] }} {{ Str::plural('page', $issues['topCheck']['count']) }} affected
            </p>
            @if ($issues['topCheck']['fix'])
                <p class="mt-1 max-w-prose text-sm text-gray-600 dark:text-gray-300">{{ $issues['topCheck']['fix'] }}</p>
            @endif
            @php $topFix = $fixRoute->for($issues['topCheck']['check']); @endphp

            <div class="mt-3 flex flex-wrap items-center gap-3">
                {{-- The primary action goes where the problem can be cleared in
                     bulk. Reading about a fix is not fixing it. --}}
                @if ($topFix)
                    <a href="{{ $topFix['url'] }}"
                       class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500">
                        {{ $topFix['label'] }} ({{ $issues['topCheck']['count'] }})
                    </a>
                @endif

                @if ($healthUrl)
                    <a href="{{ $healthUrl }}"
                       class="text-sm font-medium text-primary-700 hover:underline dark:text-primary-300">
                        See the affected pages
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- CONTEXT: how the site is trending and where it stands overall. Sits
         between the recommendation and the page list, so the numbers frame the
         work before the work is listed. --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Trend --}}
        <div class="rounded-2xl border border-gray-200 p-5 lg:col-span-2 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Clicks and impressions</h3>
            <p class="text-xs text-gray-400">Daily, last 28 days</p>

            @if ($search['series'])
                @php
                    $series = $search['series'];
                    $maxImpressions = max(1, max(array_column($series, 'impressions')));
                @endphp

                <div class="mt-4 flex h-40 items-end gap-px">
                    @foreach ($series as $day)
                        <div class="group relative flex-1"
                             title="{{ $day['date'] }}: {{ $day['clicks'] }} clicks, {{ $day['impressions'] }} impressions">
                            <div class="w-full rounded-t bg-primary-500/25"
                                 style="height: {{ max(2, (int) round($day['impressions'] / $maxImpressions * 140)) }}px"></div>
                            <div class="absolute bottom-0 w-full rounded-t bg-primary-600"
                                 style="height: {{ max(1, (int) round($day['clicks'] / $maxImpressions * 140)) }}px"></div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-2 flex gap-4 text-xs text-gray-500 dark:text-gray-400">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary-600"></span> Clicks</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary-500/25"></span> Impressions</span>
                </div>
            @else
                <p class="mt-4 text-sm text-gray-400">
                    Connect Search Console to see how this site performs in search.
                </p>
            @endif
        </div>

        {{-- On-page checklist --}}
        <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">On-page checklist</h3>
            <p class="text-xs text-gray-400">{{ $health['urls'] }} pages scanned</p>

            @if ($data['checklist'] === [])
                <p class="mt-4 text-sm text-gray-400">Run a scan to populate this.</p>
            @else
                <div class="mt-4 space-y-3">
                    @foreach ($data['checklist'] as $row)
                        <div>
                            <div class="flex justify-between text-xs font-medium">
                                <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                <span class="{{ $tone($row['rate']) }}">{{ $row['rate'] }}%</span>
                            </div>
                            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-full rounded-full {{ $row['rate'] < 41 ? 'bg-danger-500' : ($row['rate'] <= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                                     style="width: {{ $row['rate'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Findings. Page-grouped by default: the summary above answers "how am I
         doing", and an editor's next question is "which page do I open", not
         "list every rule that failed". The flat per-rule table is still one
         click away for anyone auditing a specific check. --}}
    <div class="mt-8">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pages that need work</h3>
        <p class="mb-3 text-xs text-gray-400">
            Worst first. Open the page, or clear the whole problem across the site.
        </p>

        @include('seo::filament.partials.findings-by-page', ['pages' => $byPage])

        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-primary-600 dark:text-primary-400">
                Show every finding, grouped by check
            </summary>

            <div class="mt-3">
                @include('seo::filament.partials.findings', ['data' => $this->findings()])
            </div>
        </details>
    </div>


    {{-- OPPORTUNITY: work that pays best for the effort. --}}
    {{-- Striking distance --}}
    @if ($data['striking'] !== [])
        <div class="mt-6 rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Almost on page one</h3>
            <p class="text-xs text-gray-400">
                Queries ranking 11–20. These already rank; a modest improvement moves them where people look.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Query</th>
                            <th class="py-2 pr-4 text-right">Position</th>
                            <th class="py-2 pr-4 text-right">Impressions</th>
                            <th class="py-2 text-right">Clicks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($data['striking'] as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-950 dark:text-white">{{ $row['query'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['position'], 1) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['impressions']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ number_format($row['clicks']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Top queries --}}
    @if ($search['queries'])
        <div class="mt-6 rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Top queries</h3>
            <p class="text-xs text-gray-400">
                Last 28 days. Impressions are how often this site appeared — not an estimate of how many people search the term.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Query</th>
                            <th class="py-2 pr-4 text-right">Position</th>
                            <th class="py-2 pr-4 text-right">Change</th>
                            <th class="py-2 pr-4 text-right">Impressions</th>
                            <th class="py-2 text-right">Clicks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_slice($search['queries'], 0, 10) as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-950 dark:text-white">{{ $row['query'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['position'], 1) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {!! $row['change'] === null ? '<span class="text-gray-400">new</span>' : $delta($row['change']) !!}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['impressions']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ number_format($row['clicks']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- AUDIT: whole-site checks, least urgent, longest to read. --}}
    @include('seo::filament.partials.keyword-coverage', ['coverage' => $this->keywordCoverage()])
</x-filament-panels::page>
