<?php

declare(strict_types=1);

namespace Magna\Seo\Robots;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Hands robots.txt and favicon.ico over from static files to this plugin.
 *
 * The web server serves a file in public/ before PHP runs, so a static
 * robots.txt shadows the dynamic route entirely and a static favicon.ico — on
 * a fresh Magna install an empty one — shadows the redirect to the site icon.
 * Moving them aside is the whole fix. Doing it needed a terminal, which a site
 * on shared hosting does not have, so the settings screen calls this too.
 *
 * Files are renamed to `.magna-backup`, never deleted, so whatever a site owner
 * wrote into them can always be put back with {@see restore()}.
 */
final class StaticFileTakeover
{
    /** The public files this plugin can serve itself. */
    public const FILES = ['robots.txt', 'favicon.ico'];

    public const BACKUP_SUFFIX = '.magna-backup';

    /** @param  string|null  $publicPath  null = the application's public/ directory */
    public function __construct(private readonly ?string $publicPath = null) {}

    /**
     * Files still in the way, by name.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        return array_values(array_filter(self::FILES, fn (string $file): bool => File::exists($this->path($file))));
    }

    /**
     * Move every static file aside. Per file: "moved", "absent" (nothing to
     * do), "kept" (a backup already exists, so moving would overwrite it — the
     * file is left alone and the owner decides), or "failed".
     *
     * @return array<string, string> file => outcome
     */
    public function takeOver(): array
    {
        $outcomes = [];

        foreach (self::FILES as $file) {
            $path = $this->path($file);
            $backup = $path.self::BACKUP_SUFFIX;

            if (! File::exists($path)) {
                $outcomes[$file] = 'absent';

                continue;
            }

            if (File::exists($backup)) {
                $outcomes[$file] = 'kept';

                continue;
            }

            $outcomes[$file] = $this->move($path, $backup) ? 'moved' : 'failed';
        }

        return $outcomes;
    }

    /**
     * Put the backed-up files back. Per file: "restored", "absent" (no backup),
     * "kept" (a live file exists again — it is not overwritten) or "failed".
     *
     * @return array<string, string> file => outcome
     */
    public function restore(): array
    {
        $outcomes = [];

        foreach (self::FILES as $file) {
            $path = $this->path($file);
            $backup = $path.self::BACKUP_SUFFIX;

            if (! File::exists($backup)) {
                $outcomes[$file] = 'absent';

                continue;
            }

            if (File::exists($path)) {
                $outcomes[$file] = 'kept';

                continue;
            }

            $outcomes[$file] = $this->move($backup, $path) ? 'restored' : 'failed';
        }

        return $outcomes;
    }

    private function move(string $from, string $to): bool
    {
        try {
            return File::move($from, $to);
        } catch (Throwable) {
            // A permissions problem is reported as an outcome; it must not
            // turn a settings screen into an error page.
            return false;
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->publicPath ?? public_path(), '/\\').DIRECTORY_SEPARATOR.$file;
    }
}
