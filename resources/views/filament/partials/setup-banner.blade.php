{{--
    Setup progress, shown on the dashboard until it is finished or dismissed.

    Compact by design. A full checklist at the top of a dashboard pushes the
    numbers people came for below the fold, so this shows progress plus the
    single next step and links to the full page for the rest. Expects $setup from
    SetupChecklist::progress().
--}}
<div class="rounded-2xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-500/20 dark:bg-primary-500/10">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Finish setting up SEO</h2>
            <p class="mt-0.5 text-xs text-primary-700 dark:text-primary-300">
                {{ $setup['done'] }} of {{ $setup['total'] }} steps done
            </p>
        </div>

        <button type="button" wire:click="dismissSetup"
            class="text-xs text-gray-500 hover:underline dark:text-gray-400">
            Hide this
        </button>
    </div>

    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-primary-100 dark:bg-white/10">
        <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $setup['percent'] }}%"></div>
    </div>

    @if ($setup['next'])
        <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span class="text-sm font-medium text-gray-950 dark:text-white">Next: {{ $setup['next']['title'] }}</span>

            @if ($setup['next']['url'])
                <a href="{{ $setup['next']['url'] }}"
                   class="text-xs font-medium text-primary-700 hover:underline dark:text-primary-300">
                    {{ $setup['next']['action'] ?? 'Open' }}
                </a>
            @endif
        </div>

        <p class="mt-1 max-w-prose text-xs text-gray-600 dark:text-gray-300">{{ $setup['next']['detail'] }}</p>
    @endif

    @if ($setupUrl)
        <a href="{{ $setupUrl }}" class="mt-3 inline-block text-xs font-medium text-primary-700 hover:underline dark:text-primary-300">
            See all {{ $setup['total'] }} steps
        </a>
    @endif
</div>
