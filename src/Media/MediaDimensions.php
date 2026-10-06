<?php

declare(strict_types=1);

namespace Magna\Seo\Media;

use Illuminate\Support\Facades\Storage;
use Magna\Media\Media;
use Magna\Seo\Subjects\SeoImage;
use Throwable;

/**
 * Fills in width and height for images a source described only by URL.
 *
 * Content plugins commonly store a featured image as a path and hand SEO a bare
 * URL, because that is all their own rendering needs. Open Graph and the layout
 * shift metric both need the dimensions, and the media library already knows
 * them — the two facts simply were not connected.
 *
 * Lookups are by the path embedded in the URL, memoised for the request, and
 * batched: `prime()` resolves a whole set in one query so a site-wide pass costs
 * one read rather than one per image.
 *
 * Deliberately not wired into the delivery decorator. That path is guaranteed
 * query-free per entry, and entry payloads already carry real dimensions from
 * their media fields; adding a lookup there would trade a proven guarantee for
 * a case that does not arise.
 */
final class MediaDimensions
{
    /** @var array<string, array{width: int|null, height: int|null, alt: string|null}|null> */
    private array $byPath = [];

    /**
     * Resolve many URLs in one query. Paths already known are not re-read.
     *
     * @param  list<string>  $urls
     */
    public function prime(array $urls): void
    {
        $paths = [];

        foreach ($urls as $url) {
            $path = self::pathOf($url);

            if ($path !== null && ! array_key_exists($path, $this->byPath)) {
                $paths[$path] = true;
            }
        }

        if ($paths === []) {
            return;
        }

        $found = [];

        Media::query()
            ->whereIn('path', array_keys($paths))
            ->get(['path', 'width', 'height', 'alt'])
            ->each(function (Media $media) use (&$found): void {
                $found[(string) $media->path] = [
                    'width' => $media->width,
                    'height' => $media->height,
                    'alt' => $media->alt,
                ];
            });

        foreach (array_keys($paths) as $path) {
            // Cache the misses too: an image that is not in the library will not
            // be there on the next call either, and re-asking is pure cost.
            $this->byPath[$path] = $found[$path] ?? $this->fromFile($path);
        }
    }

    /**
     * Dimensions read from the file itself, for images that never went through
     * the media library — seeded content, files dropped straight onto the disk.
     *
     * The image knows its own size; the only reason it was missing was that
     * nobody asked the file. `getimagesize` reads the header, not the pixels, so
     * this is a stat and a few bytes rather than a decode. Local disks only: a
     * remote one would turn an audit into a download.
     */
    /**
     * @return array{width: int|null, height: int|null, alt: string|null}|null
     */
    private function fromFile(string $path): ?array
    {
        try {
            $absolute = Storage::disk('public')->path($path);

            if (! is_file($absolute)) {
                return null;
            }

            $size = @getimagesize($absolute);
        } catch (Throwable) {
            // A remote or misconfigured disk has no local path; it simply yields
            // no dimensions and must never break the audit that asked.
            return null;
        }

        if ($size === false) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1], 'alt' => null];
    }

    /**
     * The same image with its dimensions filled in, or unchanged when it already
     * has them or the library has never seen it.
     */
    public function enrich(SeoImage $image): SeoImage
    {
        if ($image->width !== null && $image->height !== null) {
            return $image;
        }

        $path = self::pathOf($image->url);

        if ($path === null) {
            return $image;
        }

        if (! array_key_exists($path, $this->byPath)) {
            $this->prime([$image->url]);
        }

        $found = $this->byPath[$path] ?? null;

        if ($found === null || $found['width'] === null || $found['height'] === null) {
            return $image;
        }

        return new SeoImage(
            url: $image->url,
            id: $image->id,
            width: $found['width'],
            height: $found['height'],
            alt: $image->alt ?? $found['alt'],
            role: $image->role,
        );
    }

    /**
     * The storage path inside a public media URL.
     *
     * Media paths are stored relative ("media/2026/08/x.jpg") while the URL that
     * reaches us is absolute and may be behind a CDN, so the path is recovered
     * from the URL rather than assumed. Query strings and fragments are stripped:
     * a cache-busting parameter does not make it a different file.
     */
    public static function pathOf(string $url): ?string
    {
        $path = str_contains($url, '://')
            ? (string) parse_url($url, PHP_URL_PATH)
            : $url;

        foreach (['?', '#'] as $cut) {
            $position = strpos($path, $cut);

            if ($position !== false) {
                $path = substr($path, 0, $position);
            }
        }

        $path = ltrim(rawurldecode($path), '/');

        // Public disks are served from /storage; the stored path starts after it.
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return $path !== '' ? $path : null;
    }
}
