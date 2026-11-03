<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Seo\Robots\StaticFileTakeover;

/**
 * Backs out (or restores) the static public/robots.txt and public/favicon.ico
 * so the plugin's own routes take effect — the web server serves public/ files
 * before PHP runs, shadowing them. Files are moved to a backup, never deleted,
 * so a site owner's edits are always recoverable.
 *
 * The SEO settings screen offers the same takeover as a button, for installs
 * with no terminal; both go through {@see StaticFileTakeover}.
 */
final class SeoRobotsCommand extends Command
{
    protected $signature = 'seo:robots {--restore : Restore the previously backed-up static files}';

    protected $description = 'Take over robots.txt and favicon.ico by moving the static files in public/ aside, or --restore them.';

    private const MESSAGES = [
        'moved' => 'Moved static %s to %s.magna-backup; the plugin now serves it.',
        'absent' => 'No static %s present; the plugin already serves it.',
        'kept' => 'A backup of %s already exists; leaving the current file untouched.',
        'restored' => 'Restored the static %s.',
        'failed' => 'Could not move %s — check the permissions on public/.',
    ];

    public function handle(): int
    {
        $takeover = new StaticFileTakeover;
        $restoring = (bool) $this->option('restore');
        $failed = false;

        foreach ($restoring ? $takeover->restore() : $takeover->takeOver() as $file => $outcome) {
            if ($restoring && $outcome === 'absent') {
                $this->line("No backup of {$file} found; nothing to restore.");

                continue;
            }

            $message = sprintf(self::MESSAGES[$outcome] ?? '%s: '.$outcome, $file, $file);

            match ($outcome) {
                'kept' => $this->warn($message),
                'failed' => $this->error($message),
                default => $this->info($message),
            };

            $failed = $failed || $outcome === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
