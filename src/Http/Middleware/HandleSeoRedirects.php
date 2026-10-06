<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Magna\Seo\Redirects\NotFoundLogger;
use Magna\Seo\Redirects\RedirectStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns 404s into redirects where a rule exists, and logs the rest.
 *
 * It runs *after* the request, on the response, rather than intercepting every
 * inbound URL: a live page must never pay for a redirect lookup, and a rule that
 * accidentally shadows a real route would be far worse than one that never
 * fires. Only a genuine 404 reaches the rule set.
 */
final class HandleSeoRedirects
{
    public function __construct(
        private readonly RedirectStore $redirects,
        private readonly NotFoundLogger $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (NotFoundHttpException $e) {
            // An unmatched URL never reaches a route, so the router throws instead
            // of returning a 404 response. Both paths have to be handled: a rule
            // must fire for a URL with no route at all, and for one whose
            // controller decided the record was missing.
            $redirect = $this->resolve($request);

            if ($redirect === null) {
                throw $e;
            }

            return $redirect;
        }

        if ($response->getStatusCode() !== 404 || ! $request->isMethodCacheable()) {
            return $response;
        }

        return $this->resolve($request) ?? $response;
    }

    /**
     * The redirect for this request, or null when no rule applies (in which case
     * the 404 is logged on the way past).
     */
    private function resolve(Request $request): ?Response
    {
        if (! $request->isMethodCacheable()) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');
        $query = (string) $request->getQueryString();

        $match = $this->redirects->match($path, $query);

        if ($match === null) {
            $this->log->record($query === '' ? $path : $path.'?'.$query, $request->headers->get('referer'));

            return null;
        }

        $this->redirects->recordHit($match->ruleId);

        if ($match->isGone() || $match->target === null) {
            // 410 states the resource is permanently gone, which drops it from the
            // index faster than a 404 that might just be a transient error.
            return new SymfonyResponse('', 410);
        }

        return redirect()->away($match->target, $match->status);
    }
}
