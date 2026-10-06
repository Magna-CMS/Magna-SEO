<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Seo\Sitemap\SitemapGenerator;

/**
 * Serves the sitemap index and per-source child sitemaps. SEO registers these as
 * explicit routes so they resolve regardless of any renderer plugin's catch-all,
 * and work on a docs-only or headless install.
 *
 * Sitemaps compress extremely well, and crawlers all advertise gzip, so the body
 * is compressed transparently when the client asks for it. That is preferred over
 * publishing separate .xml.gz URLs: one canonical address per sitemap, nothing
 * for the index and the search console to disagree about.
 */
final class SitemapController
{
    public function index(Request $request, SitemapGenerator $generator): Response
    {
        return $this->xml($request, $generator->index());
    }

    /**
     * $page arrives as a route string; it is typed as one so strict_types does not
     * reject the raw value before it can be normalised.
     */
    public function child(Request $request, string $source, SitemapGenerator $generator, string $page = '1'): Response
    {
        $xml = $generator->child($source, (int) $page);

        abort_if($xml === null, 404);

        return $this->xml($request, $xml);
    }

    private function xml(Request $request, string $body): Response
    {
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];

        if ($this->acceptsGzip($request)) {
            $compressed = gzencode($body, 6);

            if ($compressed !== false) {
                $headers['Content-Encoding'] = 'gzip';
                $headers['Vary'] = 'Accept-Encoding';

                return response($compressed, 200, $headers);
            }
        }

        return response($body, 200, $headers);
    }

    private function acceptsGzip(Request $request): bool
    {
        $accept = $request->headers->get('Accept-Encoding', '');

        return is_string($accept) && str_contains(strtolower($accept), 'gzip');
    }
}
