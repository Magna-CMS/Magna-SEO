<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Backs out (or restores) the static public/robots.txt so the dynamic robots
 * route can take effect — the web server serves public/ files before PHP runs,
 * shadowing the route. The file is moved to a backup, never deleted, so a
 * site-owner's edits are always recoverable.
 */
final class SeoRobotsCommand extends Command
{
    protected $signature = 'seo:robots {--restore : Restore the previously backed-up static robots.txt}';

    protected $description = 'Take over robots.txt by moving the static public/robots.txt aside, or --restore it.';

    public function handle(): int
    {
        $path = public_path('robots.txt');
        $backup = public_path('robots.txt.magna-backup');

        if ($this->option('restore')) {
            if (! File::exists($backup)) {
                $this->warn('No backup found; nothing to restore.');

                return self::SUCCESS;
            }

            File::move($backup, $path);
            $this->info('Restored the static robots.txt.');

            return self::SUCCESS;
        }

        if (! File::exists($path)) {
            $this->info('No static robots.txt present; the dynamic route already serves.');

            return self::SUCCESS;
        }

        if (File::exists($backup)) {
            $this->warn('A backup already exists; leaving the current file untouched.');

            return self::SUCCESS;
        }

        File::move($path, $backup);
        $this->info('Moved static robots.txt to robots.txt.magna-backup; the dynamic route now serves.');

        return self::SUCCESS;
    }
}
