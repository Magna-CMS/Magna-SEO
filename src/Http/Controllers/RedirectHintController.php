<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Magna\Seo\Redirects\RedirectStore;

/**
 * Redirect hints for headless frontends.
 *
 * A Next/Nuxt/Astro site renders its own 404s and never reaches this app's
 * middleware, so it needs a way to ask "does this dead path have a redirect?"
 * before showing a not-found page. The answer is the same information a visitor
 * would get by requesting the URL directly, so it is public — but it is
 * rate-limited, and hits are deliberately not counted here: only a real
 * redirect served to a real visitor should move a rule's counter.
 */
final class RedirectHintController
{
    public function __invoke(Request $request, RedirectStore $redirects): JsonResponse
    {
        $path = $request->query('path');

        if (! is_string($path) || trim($path) === '') {
            return response()->json(['message' => 'A "path" query parameter is required.'], 422);
        }

        $query = (string) parse_url(str_contains($path, '://') ? $path : 'http://x/'.ltrim($path, '/'), PHP_URL_QUERY);
        $match = $redirects->match($path, $query);

        if ($match === null) {
            return response()->json(['redirect' => null], 404);
        }

        return response()->json([
            'redirect' => [
                'to' => $match->target,
                'status' => $match->status,
            ],
        ]);
    }
}
