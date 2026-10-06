{{--
    Shown when queued work is piling up unprocessed.

    Without this, a stalled worker is invisible: scheduled scans, IndexNow pings
    and Search Console refreshes all stop happening, and every screen carries on
    showing the last good data as though it were current. Expects $queue from
    DashboardSummary::queueHealth().
--}}
<div class="rounded-2xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
    <h2 class="text-sm font-semibold text-warning-800 dark:text-warning-200">
        Background jobs are not running
    </h2>

    <p class="mt-1 max-w-prose text-xs text-warning-700 dark:text-warning-300">
        {{ $queue['pending'] }} queued {{ \Illuminate\Support\Str::plural('job', $queue['pending']) }}
        @if ($queue['stalledMinutes'] !== null)
            have been waiting {{ $queue['stalledMinutes'] }} minutes.
        @else
            are waiting.
        @endif
        Scheduled scans, IndexNow submissions and Search Console refreshes will not
        run until a queue worker is started. Scans you start from this page run
        immediately and are unaffected.
    </p>

    <p class="mt-2 max-w-prose text-xs text-warning-700 dark:text-warning-300">
        <strong>Run pending jobs</strong> above clears what is already waiting. To keep
        it clear without anyone pressing a button, run a worker:
    </p>

    <code class="magna-seo-command mt-1">php artisan queue:work</code>
</div>
