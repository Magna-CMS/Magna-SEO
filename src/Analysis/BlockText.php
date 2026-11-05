<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * The readable text of a stored block document.
 *
 * A builder page has no body column: everything a visitor reads lives in its
 * block document, so analysing the body fields alone scored full pages as empty.
 * This reads the stored array rather than a Pages class, so SEO still runs on a
 * site without Pages, and a draft is read as readily as a published page.
 *
 * The rules match the text the Pages source gives the scan. Two readers that
 * disagreed would make a page's score move depending on which one wrote it last.
 */
final class BlockText
{
    /** Block data keys that never hold readable text. */
    private const NON_TEXT_KEYS = ['url', 'href', 'src', 'image', 'icon', 'id', 'link', 'style', 'class', 'menu', 'source', 'variant', 'experiment'];

    /**
     * @param  array<mixed>  $document
     */
    public static function from(array $document): string
    {
        $parts = [];

        self::walk($document, $parts);

        return implode("\n", $parts);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $parts
     */
    private static function walk(array $node, array &$parts): void
    {
        if (isset($node['block']) && is_array($node['data'] ?? null)) {
            array_walk_recursive($node['data'], static function (mixed $value, int|string $key) use (&$parts): void {
                if (! is_string($value) || in_array($key, self::NON_TEXT_KEYS, true)) {
                    return;
                }

                $text = self::clean($value);

                if ($text !== '' && preg_match('#^(https?:)?//|^/[^\s]*$|^\#[0-9a-f]{3,8}$#i', $text) !== 1) {
                    $parts[] = $text;
                }
            });
        }

        foreach ($node as $key => $child) {
            if (is_array($child) && $key !== 'data') {
                self::walk($child, $parts);
            }
        }
    }

    private static function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
