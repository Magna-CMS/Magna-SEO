<?php

declare(strict_types=1);

namespace Magna\Seo\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Magna\Seo\Filament\Pages\SeoHealthPage;
use Magna\Seo\Scan\ScanDelta;
use Magna\Seo\Support\FixInstructions;
use Throwable;

/**
 * Tells the people responsible for a site that something new broke.
 *
 * Deliberately narrow. It fires only on *new* errors, never on the running
 * total, and never on warnings or notices — a notification that arrives every
 * week saying the same thing trains its reader to dismiss it unread, at which
 * point the genuinely urgent one is dismissed too.
 */
final class ScanFindingsNotification
{
    /**
     * Build the notification for a delta, or null when there is nothing worth
     * saying.
     */
    public static function make(ScanDelta $delta): ?Notification
    {
        if (! $delta->isNoteworthy()) {
            return null;
        }

        $errors = $delta->newErrors();
        $count = count($errors);

        $title = $delta->isFirstScan
            ? "First SEO scan complete: {$delta->totalIssues} issue(s) found"
            : ($count === 1 ? 'A new SEO error was found' : "{$count} new SEO errors were found");

        $notification = Notification::make()
            ->title($title)
            ->body(self::body($delta))
            ->danger()
            ->persistent();

        try {
            $notification->actions([
                Action::make('view')
                    ->label('Open SEO Health')
                    ->url(SeoHealthPage::getUrl())
                    ->markAsRead(),
            ]);
        } catch (Throwable) {
            // No panel context (a queued scan on a console worker). The
            // notification is still worth sending without its button.
        }

        return $notification;
    }

    /**
     * Lead with the first finding and its remedy rather than a count. Someone
     * reading this on a phone should be able to tell whether to act now.
     */
    private static function body(ScanDelta $delta): string
    {
        $errors = $delta->newErrors();
        $first = $errors[0] ?? ($delta->newIssues[0] ?? null);

        if ($first === null) {
            return "{$delta->totalIssues} issue(s) in total.";
        }

        $body = $first->message;

        if (is_string($first->url) && $first->url !== '') {
            $body .= ' ('.$first->url.')';
        }

        $fix = FixInstructions::for($first->check);

        if ($fix !== null) {
            $body .= ' '.$fix;
        }

        $others = count($errors) - 1;

        if ($others > 0) {
            $body .= " Plus {$others} more.";
        }

        if ($delta->resolved > 0) {
            $body .= " ({$delta->resolved} previous issue(s) were fixed.)";
        }

        return $body;
    }
}
