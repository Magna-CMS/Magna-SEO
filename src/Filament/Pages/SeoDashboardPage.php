<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Magna\Seo\Dashboard\DashboardSummary;
use Magna\Seo\Dashboard\SetupChecklist;
use Magna\Seo\Jobs\RunSiteScanJob;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Scan\KeywordCoverage;
use Magna\Seo\Scan\ScanSummary;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Settings\SeoSettings;
use UnitEnum;

/**
 * The SEO overview: how the site is doing, what is wrong with it, and what to do
 * next — in that order, because that is the order the questions are asked in.
 *
 * Every number here is either already stored or already cached. The page makes
 * no live third-party call, so it renders at the same speed whether or not
 * Search Console is reachable.
 */
class SeoDashboardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'SEO Dashboard';

    protected static ?string $slug = 'seo-dashboard';

    protected string $view = 'seo::filament.seo-dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seo.scans.run') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $summary = app(DashboardSummary::class);
        $search = $summary->search();

        return [
            'health' => $summary->health(),
            'issues' => $summary->issues(),
            'checklist' => $summary->checklist(),
            'search' => $search,
            'striking' => $summary->strikingDistance($search['queries']),
            'notFound' => $summary->notFoundCount(),
        ];
    }

    /**
     * Setup progress, or null once there is nothing left to prompt about.
     *
     * @return array{done: int, total: int, percent: int, complete: bool, next: array<string, mixed>|null}|null
     */
    public function setup(): ?array
    {
        $checklist = app(SetupChecklist::class);

        return $checklist->shouldPrompt() ? $checklist->progress() : null;
    }

    /**
     * Stop offering setup. Sets the same flag the setup page's own dismiss does,
     * so the two cannot disagree about whether the prompt is finished with.
     */
    public function dismissSetup(): void
    {
        $settings = SeoSettings::get();
        $settings->setup_completed = true;
        $settings->save();

        Notification::make()->title('Setup hidden. Find it again under SEO settings.')->success()->send();
    }

    /**
     * The full findings list, previously its own screen.
     *
     * @return array{scan: SeoScan|null, bySeverity: array<string, int>, byCheck: array<string, int>, issues: Collection<int, SeoScanIssue>}
     */
    public function findings(): array
    {
        $summary = app(ScanSummary::class);
        $scan = $summary->latest();

        if ($scan === null) {
            return ['scan' => null, 'bySeverity' => [], 'byCheck' => [], 'issues' => collect()];
        }

        return [
            'scan' => $scan,
            'bySeverity' => $summary->countsBySeverity($scan),
            'byCheck' => $summary->countsByCheck($scan),
            'issues' => $summary->recentIssues($scan, 50),
        ];
    }

    /**
     * The same findings, grouped by the page they affect.
     *
     * @return list<array{url: string|null, source: string, title: string, worst: string, count: int, issues: list<SeoScanIssue>}>
     */
    public function issuesByPage(): array
    {
        $summary = app(ScanSummary::class);
        $scan = $summary->latest();

        return $scan === null ? [] : $summary->issuesByPage($scan, 25);
    }

    /**
     * Queue health, or null while it is fine — a warning that is always on
     * screen stops being read.
     *
     * @return array{driver: string, pending: int, stalledMinutes: int|null, healthy: bool}|null
     */
    public function queue(): ?array
    {
        $health = app(DashboardSummary::class)->queueHealth();

        return $health['healthy'] ? null : $health;
    }

    /**
     * @return array{total: int, covered: int, uncovered: list<array{source: string, title: string, url: string}>, cannibalised: array<string, list<array{source: string, title: string, url: string}>>}
     */
    public function keywordCoverage(): array
    {
        return app(KeywordCoverage::class)->report();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Run scan')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    // Runs inline, deliberately. Queueing it means the button does
                    // nothing at all on an install without a worker — and it
                    // reports success while doing it, which is the worst kind of
                    // broken. A scan of a normal site takes seconds; for one large
                    // enough to matter there is `seo:scan --queue`.
                    $scan = app(SiteScanner::class)->scan();

                    Notification::make()
                        ->title("Scan complete — {$scan->url_count} URLs, {$scan->issue_count} issue(s).")
                        ->success()
                        ->send();
                }),

            Action::make('drainQueue')
                ->label('Run pending jobs')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->queue() !== null
                    && (auth()->user()?->can('seo.settings.manage') ?? false))
                ->requiresConfirmation()
                ->modalHeading('Run pending jobs now')
                ->modalDescription('This works through every queued job for this site, not only SEO\'s, and stops when the queue is empty. It does not start a background worker — scheduled work still needs one.')
                ->modalSubmitActionLabel('Run them')
                ->action(function (): void {
                    $before = app(DashboardSummary::class)->queueHealth()['pending'];

                    // Bounded and synchronous, deliberately. Spawning a real
                    // queue:work daemon from a web request gives an unsupervised
                    // process that dies with the request or outlives it with
                    // nothing to restart or monitor it; draining what is already
                    // queued is the part that can be done safely from a button.
                    // The time cap keeps it inside the request's own limit.
                    Artisan::call('queue:work', [
                        '--stop-when-empty' => true,
                        '--max-time' => 20,
                        '--no-interaction' => true,
                    ]);

                    $after = app(DashboardSummary::class)->queueHealth()['pending'];
                    $done = max(0, $before - $after);

                    $notification = Notification::make()
                        ->title("Ran {$done} ".Str::plural('job', $done).'.');

                    if ($after > 0) {
                        $notification
                            ->body("{$after} still queued — press again to continue, or start a worker to keep it clear.")
                            ->warning();
                    } else {
                        $notification->body('The queue is empty.')->success();
                    }

                    $notification->send();
                }),

            Action::make('scanInBackground')
                ->label('Run in background')
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => app(DashboardSummary::class)->queueIsProcessing())
                ->action(function (): void {
                    RunSiteScanJob::dispatch();

                    Notification::make()
                        ->title('Scan queued')
                        ->body('You will be notified if it finds anything new.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
