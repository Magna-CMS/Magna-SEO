<?php

declare(strict_types=1);

namespace Magna\Seo\Meta;

use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Per-content-type SEO defaults, layered over the site-wide ones.
 *
 * A blog post and a documentation page do not want the same title format, and
 * forcing one template on both means every site ends up overriding it by hand on
 * every page — which is the work the templates existed to avoid.
 *
 * Precedence, strongest last: site default, then the content type's default,
 * then the per-page override (applied later, by {@see MetaResolver}). Only keys
 * a type actually sets are layered, so a type that only customises its title
 * keeps the site description template.
 *
 * Pure: reads the settings object it is handed and touches no database.
 */
final class TypeDefaults
{
    /**
     * A subject's content type, by convention `raw['content_type']`. Sources
     * that do not supply one simply get the site defaults.
     */
    public static function typeOf(SeoSubject $subject): ?string
    {
        $type = $subject->raw['content_type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    public static function titleTemplate(SeoSubject $subject, SeoSettings $settings): string
    {
        return self::value($subject, $settings, 'title_template')
            ?? $settings->default_title_template;
    }

    public static function descriptionTemplate(SeoSubject $subject, SeoSettings $settings): string
    {
        return self::value($subject, $settings, 'description_template')
            ?? $settings->default_description_template;
    }

    /**
     * Whether this content type should be indexed at all.
     *
     * Some types exist only to be embedded — a testimonial, a nav block — and
     * having each one indexed as its own thin page actively hurts a site. This
     * can only remove indexability, never grant it: a draft stays a draft.
     */
    public static function isIndexable(SeoSubject $subject, SeoSettings $settings): bool
    {
        $value = self::raw($subject, $settings, 'robots_index');

        return ! is_bool($value) || $value;
    }

    /**
     * A schema.org type this content type should be published as, if it names one.
     */
    public static function schemaType(SeoSubject $subject, SeoSettings $settings): ?string
    {
        return self::value($subject, $settings, 'schema_type');
    }

    private static function value(SeoSubject $subject, SeoSettings $settings, string $key): ?string
    {
        $value = self::raw($subject, $settings, $key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function raw(SeoSubject $subject, SeoSettings $settings, string $key): mixed
    {
        $type = self::typeOf($subject);

        if ($type === null) {
            return null;
        }

        $defaults = $settings->type_defaults[$type] ?? null;

        return is_array($defaults) ? ($defaults[$key] ?? null) : null;
    }
}
