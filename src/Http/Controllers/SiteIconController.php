<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Http\Response;
use Magna\Seo\Media\SocialImageResolver;
use Magna\Seo\Settings\SeoSettings;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * /favicon.ico and /apple-touch-icon.png, for the clients that never read the
 * page head: crawlers that fetch the root icon directly (Google's favicon
 * fetcher among them), feed readers, and iOS home-screen bookmarks.
 *
 * Both redirect to the site icon from SEO settings. With no icon configured the
 * answer is a plain 404 — cheaper than a themed error page, and what a missing
 * icon has always been.
 *
 * Only reachable once the static public/favicon.ico has been moved aside (see
 * StaticFileTakeover): the web server serves a file before PHP runs.
 */
final class SiteIconController
{
    public function __invoke(SocialImageResolver $images): BaseResponse
    {
        $icon = $images->siteIcon(SeoSettings::get());

        if ($icon === null) {
            return new Response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // Permanent, but cached for a day rather than forever: a browser keeps
        // a bare 301 indefinitely, and a new icon gets a new media URL.
        return redirect()->away($icon->url, 301, ['Cache-Control' => 'public, max-age=86400']);
    }
}
