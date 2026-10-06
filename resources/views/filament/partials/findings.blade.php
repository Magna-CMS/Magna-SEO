{{--
    Scan findings.

    Shared by the dashboard and the (deep-link-only) Health page so the two can
    never drift. Expects $data from ScanSummary-shaped state: scan, bySeverity,
    issues.

    Every row carries its remedy, because a finding an editor cannot act on is
    just a complaint.
--}}
@php
    $findingLink = app(\Magna\Seo\Support\FindingLink::class);
@endphp

@if ($data['scan'] === null)
    <div class="rounded-xl border border-dashed p-8 text-center text-sm text-gray-500 dark:border-gray-700">
        No scan has run yet. Use <strong>Run scan</strong> above to check the site.
    </div>
@else
    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600 dark:text-gray-300">
        <span>Last scan {{ $data['scan']->created_at?->diffForHumans() }}</span>
        <span>·</span>
        <span>{{ $data['scan']->url_count }} URLs</span>
        <span>·</span>
        <span>{{ $data['scan']->issue_count }} issues</span>
    </div>

    <div class="mt-4 flex flex-wrap gap-3">
        @foreach ($data['bySeverity'] as $severity => $count)
            <span @class([
                'inline-flex items-center gap-2 rounded-lg px-3 py-1 text-sm font-medium',
                'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $severity === 'error',
                'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => $severity === 'warning',
                'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $severity === 'notice',
            ])>
                {{ ucfirst($severity) }}: {{ $count }}
            </span>
        @endforeach
    </div>

    @if ($data['issues']->isNotEmpty())
        <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-2">Source</th>
                        <th class="px-4 py-2">Check</th>
                        <th class="px-4 py-2">Severity</th>
                        <th class="px-4 py-2">Detail</th>
                        <th class="px-4 py-2">Fix</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($data['issues'] as $issue)
                        @php
                            $remedy = \Magna\Seo\Support\FixInstructions::for($issue->check);
                            $link = $findingLink->for($issue);
                        @endphp

                        <tr>
                            <td class="px-4 py-2">{{ $issue->source }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $issue->check }}</td>
                            <td class="px-4 py-2">{{ $issue->severity->value }}</td>
                            <td class="px-4 py-2 text-gray-600 dark:text-gray-300">
                                {{ $issue->message }}
                                @if ($issue->url)
                                    <div class="text-xs text-gray-400">{{ $issue->url }}</div>
                                @endif

                                @if ($remedy)
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs font-medium text-primary-600 dark:text-primary-400">
                                            How to fix this
                                        </summary>
                                        <p class="mt-1 max-w-prose text-xs text-gray-500 dark:text-gray-400">{{ $remedy }}</p>
                                    </details>
                                @endif
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($link)
                                    <a href="{{ $link['url'] }}"
                                       @if ($link['external']) target="_blank" rel="noopener noreferrer" @endif
                                       class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $link['label'] }}
                                    </a>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="mt-6 rounded-xl border border-dashed p-6 text-center text-sm text-success-600 dark:border-gray-700">
            No issues found. The site is clean.
        </div>
    @endif
@endif
