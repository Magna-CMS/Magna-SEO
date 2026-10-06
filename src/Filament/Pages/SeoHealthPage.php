<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Models\SeoScanIssue;
use Magna\Seo\Scan\KeywordCoverage;
use Magna\Seo\Scan\ScanSummary;
use Magna\Seo\Scan\SiteScanner;
use UnitEnum;

/**
 * SEO Health dashboard: the latest site-scan result, issue counts by severity and
 * by check, and the findings — with a button to run a fresh scan.
 */
class SeoHealthPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Health';

    protected static ?int $navigationSort = 1;

    /**
     * Reachable, but not in the sidebar.
     *
     * Everything this page shows now also appears on the dashboard, and two
     * navigation entries for one subject is one too many. The page stays because
     * scan notifications and the dashboard's own "start here" card link straight
     * to it — removing the route would break those links to save a menu item.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $title = 'SEO Health';

    protected static ?string $slug = 'seo-health';

    protected string $view = 'seo::filament.seo-health';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seo.scans.run') ?? false;
    }

    /**
     * Data for the view: the latest scan and its rollups, or a null scan when none
     * has run yet.
     *
     * @return array{scan: SeoScan|null, bySeverity: array<string, int>, byCheck: array<string, int>, issues: Collection<int, SeoScanIssue>}
     */
    public function summaryData(): array
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
     * Site-wide keyword coverage: pages nobody decided a purpose for, and pages
     * competing with each other for the same one.
     *
     * @return array{total: int, covered: int, uncovered: list<array{source: string, title: string, url: string}>, cannibalised: array<string, list<array{source: string, title: string, url: string}>>}
     */
    public function keywordCoverage(): array
    {
        return app(KeywordCoverage::class)->report();
    }

    public function runScan(): void
    {
        $scan = app(SiteScanner::class)->scan();

        Notification::make()
            ->title("Scan complete — {$scan->url_count} URLs, {$scan->issue_count} issues.")
            ->success()
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_scan')
                ->label('Run scan')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->runScan()),
        ];
    }
}
