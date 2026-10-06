<?php

declare(strict_types=1);

namespace Magna\Seo\Head;

use Magna\Seo\Subjects\SeoSubject;

/**
 * Renders a subject's breadcrumb trail as HTML.
 *
 * The plugin already emits `BreadcrumbList` structured data from the same trail,
 * but structured data describes a page rather than being one: a crawler reading
 * it still expects to find real, followable links, and a visitor gets nothing
 * from JSON-LD at all. This closes that gap with markup that matches what the
 * schema claims.
 *
 * Output is a plain `<nav><ol>` with the accessibility attributes screen readers
 * use, escaped in context. The last crumb is the current page and is deliberately
 * not a link — linking a page to itself is noise for everyone.
 */
final class BreadcrumbRenderer
{
    public function render(SeoSubject $subject, string $separator = '/'): string
    {
        if ($subject->breadcrumbs === []) {
            return '';
        }

        $items = [];
        $last = count($subject->breadcrumbs) - 1;

        foreach ($subject->breadcrumbs as $index => $crumb) {
            $label = $this->escape($crumb->label);
            $isCurrent = $index === $last;

            $content = ($crumb->url !== null && $crumb->url !== '' && ! $isCurrent)
                ? '<a href="'.$this->escape($crumb->url).'">'.$label.'</a>'
                : '<span'.($isCurrent ? ' aria-current="page"' : '').'>'.$label.'</span>';

            if ($index > 0) {
                // The separator is decorative; announcing it would have a screen
                // reader read "slash" between every level.
                $content = '<span aria-hidden="true">'.$this->escape($separator).'</span> '.$content;
            }

            $items[] = '<li>'.$content.'</li>';
        }

        return '<nav aria-label="Breadcrumb"><ol>'.implode('', $items).'</ol></nav>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
