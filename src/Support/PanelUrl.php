<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Throwable;

/**
 * A link to another admin page, or nothing.
 *
 * Filament resolves a page's URL from a registered panel route. That route is
 * not guaranteed: a page can be removed from the plugin's registration, hidden
 * by permissions, or simply not registered in whatever context is rendering —
 * and `getUrl()` throws rather than returning null when it is missing.
 *
 * One screen linking to another should never be able to take the first one down.
 * Callers render the link when there is one and omit it when there is not.
 */
final class PanelUrl
{
    /**
     * @param  class-string  $page
     */
    public static function for(string $page, string $name = 'index'): ?string
    {
        if (! method_exists($page, 'getUrl')) {
            return null;
        }

        try {
            /** @var string $url */
            $url = $page::getUrl();

            return $url;
        } catch (Throwable) {
            return null;
        }
    }
}
