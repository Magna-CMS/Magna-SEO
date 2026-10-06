<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Magna\Seo\Dashboard\ContentOverview;
use Magna\Seo\Filament\Pages\SeoContentPage;
use Magna\Seo\Filament\Resources\SeoRedirectResource;

/**
 * Where to go to fix every page with a given problem, as opposed to one page.
 *
 * {@see FindingLink} answers "open the page this finding is about". That is the
 * right link for one row and the wrong one for forty: nobody fixes forty absent
 * meta descriptions by opening forty tabs. This answers the other question —
 * "show me all of these somewhere I can clear them" — and points at the screen
 * that can actually do the batch.
 *
 * Only checks with a real bulk destination appear here. A "Fix these" button
 * that lands on a list which cannot fix the thing is worse than no button, so
 * an unmapped check returns null and the caller falls back to the per-page link.
 */
final class FixRoute
{
    /**
     * Checks the content list can clear in place, mapped to the filter that
     * isolates them.
     */
    private const CONTENT_FILTERS = [
        'missing-description' => ContentOverview::FILTER_NO_DESCRIPTION,
        'duplicate-description' => ContentOverview::FILTER_NO_DESCRIPTION,
        'missing-title' => ContentOverview::FILTER_NEEDS_WORK,
        'duplicate-title' => ContentOverview::FILTER_NEEDS_WORK,
        'thin-content' => ContentOverview::FILTER_NEEDS_WORK,
        'keyword-in-title' => ContentOverview::FILTER_NO_KEYWORD,
        'keyword-in-description' => ContentOverview::FILTER_NO_KEYWORD,
        'keyword-in-slug' => ContentOverview::FILTER_NO_KEYWORD,
        'keyword-in-opening' => ContentOverview::FILTER_NO_KEYWORD,
        'keyword-in-heading' => ContentOverview::FILTER_NO_KEYWORD,
        'keyword-density' => ContentOverview::FILTER_NO_KEYWORD,
    ];

    /**
     * The content-list filter that isolates a check, or null when the list
     * cannot clear it.
     *
     * Split out from {@see self::for()} deliberately: resolving the URL needs a
     * booted panel, the mapping does not, and the mapping is the part that rots.
     * A check id renamed upstream silently loses its fix button — exactly the
     * kind of decay a test should catch without needing a panel.
     */
    public function filterFor(string $check): ?string
    {
        return self::CONTENT_FILTERS[$check] ?? null;
    }

    /**
     * Every check this class claims it can bulk-fix. Exposed so a test can
     * verify the ids are real, since a check renamed elsewhere would otherwise
     * lose its fix button in silence.
     *
     * @return list<string>
     */
    public function mappedChecks(): array
    {
        return array_keys(self::CONTENT_FILTERS);
    }

    public function isRedirectCheck(string $check): bool
    {
        return str_starts_with($check, 'redirect-') || $check === 'sitemap-redirect';
    }

    /**
     * @return array{url: string, label: string}|null
     */
    public function for(string $check): ?array
    {
        if ($this->isRedirectCheck($check)) {
            $url = PanelUrl::for(SeoRedirectResource::class);

            return $url === null ? null : ['url' => $url, 'label' => 'Open redirects'];
        }

        $filter = $this->filterFor($check);

        if ($filter === null) {
            return null;
        }

        $url = PanelUrl::for(SeoContentPage::class);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url.'?filter='.urlencode($filter),
            'label' => 'Fix these',
        ];
    }
}
