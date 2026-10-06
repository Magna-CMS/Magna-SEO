@php
    $steps = $this->steps();
    $done = count(array_filter($steps, fn ($step) => $step['done']));
@endphp

<x-filament-panels::page>
    <div class="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
        <div class="flex items-baseline justify-between">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ $done }} of {{ count($steps) }} steps done
            </h2>
            <button type="button" wire:click="markComplete"
                class="text-xs text-gray-500 hover:underline dark:text-gray-400">
                Hide this checklist
            </button>
        </div>

        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div class="h-full rounded-full bg-primary-600"
                 style="width: {{ (int) round($done / max(count($steps), 1) * 100) }}%"></div>
        </div>
    </div>

    <div class="mt-4 space-y-3">
        @foreach ($steps as $step)
            <div class="flex gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div @class([
                    'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                    'bg-success-500 text-white' => $step['done'],
                    'border border-gray-300 text-gray-400 dark:border-gray-600' => ! $step['done'],
                ])>
                    {{ $step['done'] ? '✓' : '' }}
                </div>

                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $step['title'] }}</div>
                    <p class="mt-0.5 max-w-prose text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>

                    @unless ($step['done'])
                        @if ($step['id'] === 'scan')
                            <button type="button" wire:click="runScan"
                                class="mt-2 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-500">
                                Run now
                            </button>
                        @elseif ($step['url'])
                            <a href="{{ $step['url'] }}"
                               class="mt-2 inline-block text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $step['action'] ?? 'Open' }}
                            </a>
                        @endif
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-6 max-w-prose text-xs text-gray-500 dark:text-gray-400">
        A note on expectations: no plugin decides rankings — content quality, topical authority and
        backlinks do, against whoever else wants that query. What this one promises is narrower and
        checkable: every technical blocker it can detect is either fixed or listed on the dashboard.
    </p>
</x-filament-panels::page>
