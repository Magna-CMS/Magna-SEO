<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Illuminate\Support\Facades\Log;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Notifications\ScanFindingsNotification;
use Magna\Seo\Users\NotifiableOperators;
use Throwable;

/**
 * Delivers what a scan found to the people who can act on it.
 *
 * Separated from {@see SiteScanner} because scanning and telling someone are
 * different concerns with different failure modes: a notification backend being
 * down must never lose a scan that already ran and already wrote its results.
 * Everything here is therefore best-effort and logged.
 */
final class ScanReporter
{
    public function __construct(private readonly NotifiableOperators $operators) {}

    public function report(SeoScan $scan): void
    {
        try {
            $previous = SeoScan::query()
                ->where('id', '<', $scan->id)
                ->latest('id')
                ->first();

            $delta = ScanDelta::between($scan, $previous);
            $notification = ScanFindingsNotification::make($delta);

            if ($notification === null) {
                return;
            }

            foreach ($this->operators->withPermission('seo.scans.run') as $user) {
                $notification->sendToDatabase($user);
            }
        } catch (Throwable $e) {
            Log::warning('SEO scan notification could not be delivered.', [
                'scan' => $scan->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
