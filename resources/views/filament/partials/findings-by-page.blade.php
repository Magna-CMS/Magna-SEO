{{--
    Findings grouped by the page they affect.

    The flat findings table answers "what is wrong with the site". This answers
    "which page do I open next", which is the question an editor actually has —
    and it keeps one page's problems together instead of scattering them across
    four rows that look unrelated.

    Expects $pages from ScanSummary::issuesByPage().
--}}
@php
    $fixRoute = app(\Magna\Seo\Support\FixRoute::class);
@endphp

@if ($pages === [])
    <div class="rounded-xl border border-dashed p-6 text-center text-sm text-success-600 dark:border-gray-700">
        No page-level issues. Every scanned URL passed.
    </div>
@else
    <div class="space-y-3">
        @foreach ($pages as $page)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'inline-flex h-2 w-2 shrink-0 rounded-full',
                                'bg-danger-500' => $page['worst'] === 'error',
                                'bg-warning-500' => $page['worst'] === 'warning',
                                'bg-gray-400' => $page['worst'] === 'notice',
                            ])></span>

                            <span class="truncate font-medium text-gray-950 dark:text-white">{{ $page['title'] }}</span>

                            @if ($page['source'])
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                    {{ $page['source'] }}
                                </span>
                            @endif

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $page['count'] }} {{ \Illuminate\Support\Str::plural('issue', $page['count']) }}
                            </span>
                        </div>

                        @if ($page['url'])
                            <div class="mt-0.5 truncate text-xs text-gray-400">{{ $page['url'] }}</div>
                        @endif
                    </div>

                    @if ($page['url'])
                        <a href="{{ $page['url'] }}" target="_blank" rel="noopener noreferrer"
                           class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-white/5">
                            Open page
                        </a>
                    @endif
                </div>

                <ul class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    @foreach ($page['issues'] as $issue)
                        @php
                            $remedy = \Magna\Seo\Support\FixInstructions::for($issue->check);
                            $fix = $fixRoute->for($issue->check);
                        @endphp

                        <li class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <span class="text-sm text-gray-700 dark:text-gray-200">{{ $issue->message }}</span>

                                @if ($remedy)
                                    <details class="mt-0.5">
                                        <summary class="cursor-pointer text-xs font-medium text-primary-600 dark:text-primary-400">
                                            How to fix this
                                        </summary>
                                        <p class="mt-1 max-w-prose text-xs text-gray-500 dark:text-gray-400">{{ $remedy }}</p>
                                    </details>
                                @endif
                            </div>

                            @if ($fix)
                                <a href="{{ $fix['url'] }}"
                                   class="shrink-0 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-500">
                                    {{ $fix['label'] }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif
