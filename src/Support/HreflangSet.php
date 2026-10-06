<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Magna\Seo\Subjects\SeoSubject;

/**
 * Turns a subject's locale alternates into the hreflang link set emitted on both
 * the rendered head and the sitemap — one source of truth, so the two never
 * disagree. Emitted only for a real translation set (two or more locales), with
 * an x-default pointing at the first (default) alternate.
 */
final class HreflangSet
{
    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public static function forSubject(SeoSubject $subject): array
    {
        if (count($subject->alternates) < 2) {
            return [];
        }

        $links = [];
        foreach ($subject->alternates as $locale => $href) {
            $links[] = ['hreflang' => $locale, 'href' => $href];
        }

        // x-default points at the first (default) alternate; the set is non-empty
        // here, so a first key always exists.
        $default = (string) array_key_first($subject->alternates);
        $links[] = ['hreflang' => 'x-default', 'href' => $subject->alternates[$default]];

        return $links;
    }
}
