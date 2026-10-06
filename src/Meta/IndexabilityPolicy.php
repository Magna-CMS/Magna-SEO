<?php

declare(strict_types=1);

namespace Magna\Seo\Meta;

use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Single source of truth for the robots meta directive. Precedence, strongest
 * last: the subject's own indexability, then the environment guards (non-production,
 * maintenance mode, preview requests), then the per-entity override. A page can
 * always be pushed *out* of the index (by env or by an editor), but a subject the
 * source deemed non-indexable is never forced back in.
 */
final class IndexabilityPolicy
{
    /**
     * @param  bool  $isPreview  True for a preview-token request — always noindex, no exceptions.
     * @param  bool  $isMaintenance  True while the app is down for maintenance.
     */
    public function __construct(
        private readonly bool $isProduction,
        private readonly bool $isPreview = false,
        private readonly bool $isMaintenance = false,
    ) {}

    /**
     * Build the `robots` meta content, e.g. "index, follow" or
     * "noindex, nofollow, max-image-preview:large".
     */
    public function robotsContent(SeoSubject $subject, ?SeoMeta $override, SeoSettings $settings): string
    {
        $index = $subject->indexable;
        $follow = true;

        // Staging/dev must never be indexed unless deliberately allowed.
        if (! $this->isProduction && $settings->noindex_non_production) {
            $index = false;
        }

        // A preview renders unpublished content at a shareable URL; it is never
        // indexable, and unlike the environment guard this is not configurable.
        // Maintenance mode is transient and equally must not be captured.
        if ($this->isPreview || $this->isMaintenance) {
            $index = false;
        }

        if ($override !== null) {
            // An explicit editor noindex wins; it can only remove, never re-add,
            // indexability the environment or source already denied. A column not
            // set on the override (e.g. an override that only tweaks follow)
            // coerces to the safe default of true rather than flipping to noindex.
            $index = $index && $this->boolOrTrue($override->getAttribute('robots_index'));
            $follow = $this->boolOrTrue($override->getAttribute('robots_follow'));
        }

        $directives = [
            $index ? 'index' : 'noindex',
            $follow ? 'follow' : 'nofollow',
        ];

        foreach ($this->advancedDirectives($override) as $directive) {
            $directives[] = $directive;
        }

        return implode(', ', $directives);
    }

    /**
     * A tri-state robots flag: only an explicit false disables; null (column not
     * set on an unsaved override) means "unspecified", which is the safe default.
     */
    private function boolOrTrue(mixed $value): bool
    {
        return $value === null ? true : (bool) $value;
    }

    /**
     * @return list<string>
     */
    private function advancedDirectives(?SeoMeta $override): array
    {
        if ($override === null) {
            return [];
        }

        $advanced = $override->robots_advanced;
        if (! is_array($advanced)) {
            return [];
        }

        $allowed = ['noarchive', 'nosnippet', 'noimageindex', 'notranslate'];
        $out = [];

        foreach ($allowed as $flag) {
            if (($advanced[$flag] ?? false) === true) {
                $out[] = $flag;
            }
        }

        foreach (['max-snippet', 'max-image-preview', 'max-video-preview'] as $key) {
            $value = $advanced[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $out[] = $key.':'.$value;
            } elseif (is_int($value)) {
                $out[] = $key.':'.$value;
            }
        }

        return $out;
    }
}
