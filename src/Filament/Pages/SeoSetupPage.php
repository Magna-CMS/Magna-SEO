<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Seo\Dashboard\SetupChecklist;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Settings\SeoSettings;
use UnitEnum;

/**
 * First-run setup.
 *
 * A plugin that needs configuring before it does anything useful, and never asks
 * for it, ends up unconfigured — which is how sites end up with a perfectly good
 * SEO plugin installed and no site name, no templates and no scan ever run.
 *
 * This is a checklist rather than a linear wizard: every step reports whether it
 * is already done, so someone arriving halfway through a migration sees what is
 * left instead of being walked through decisions they already made.
 */
class SeoSetupPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Setup';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'SEO Setup';

    protected static ?string $slug = 'seo-setup';

    protected string $view = 'seo::filament.seo-setup';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seo.settings.manage') ?? false;
    }

    /**
     * Hide the nav entry once setup is finished, so it stops competing with the
     * screens people use daily.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && ! SeoSettings::get()->setup_completed;
    }

    /**
     * @return list<array{id: string, title: string, done: bool, detail: string, url: string|null, action: string|null}>
     */
    public function steps(): array
    {
        return app(SetupChecklist::class)->steps();
    }

    public function runScan(): void
    {
        // Inline, not queued. A setup step that reports success while silently
        // doing nothing — which is what dispatching to an unmanned queue does —
        // is worse than one that takes a few seconds.
        $scan = app(SiteScanner::class)->scan();

        Notification::make()
            ->title("Scan complete — {$scan->url_count} URLs, {$scan->issue_count} issue(s).")
            ->body('Full results are on the SEO dashboard.')
            ->success()
            ->send();
    }

    /**
     * Dismissing hides the checklist rather than pretending the work is done —
     * the remaining steps stay visible on the dashboard either way.
     */
    public function markComplete(): void
    {
        $settings = SeoSettings::get();
        $settings->setup_completed = true;
        $settings->save();

        Notification::make()->title('Setup marked complete.')->success()->send();
    }
}
