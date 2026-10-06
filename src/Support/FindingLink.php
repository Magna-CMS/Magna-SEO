<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Magna\Seo\Filament\Resources\SeoRedirectResource;
use Magna\Seo\Models\SeoScanIssue;

/**
 * Where to go to fix one finding.
 *
 * Lifted out of the Health page so the dashboard can show the same findings with
 * the same links: two screens rendering the same list must not each carry their
 * own idea of where a fix lives.
 *
 * Findings that own an admin screen link to it. Everything else links to the
 * affected page — SEO has no map from a content subject back to the admin form
 * that edits it, since that would have to come from the source plugin, so
 * opening the page is the honest destination rather than a guessed admin URL
 * that may 404.
 */
final class FindingLink
{
    /**
     * @return array{url: string, label: string, external: bool}|null
     */
    public function for(SeoScanIssue $issue): ?array
    {
        if (str_starts_with($issue->check, 'redirect-') || $issue->check === 'sitemap-redirect') {
            $url = PanelUrl::for(SeoRedirectResource::class);

            if ($url !== null) {
                return ['url' => $url, 'label' => 'Redirects', 'external' => false];
            }
        }

        if ($issue->check === 'robots-sitemap-conflict') {
            return ['url' => url('robots.txt'), 'label' => 'robots.txt', 'external' => true];
        }

        $url = $issue->url;

        if (! is_string($url) || $url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        return ['url' => $url, 'label' => 'Open page', 'external' => true];
    }
}
