<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The two request-scoped facts the indexability policy needs: whether this is a
 * preview render, and whether the site is in maintenance mode. Isolated here so
 * the policy itself stays a pure function of its inputs and remains trivially
 * testable without a request.
 */
final class RequestContext
{
    public function __construct(private readonly Application $app) {}

    /**
     * A preview renders unpublished content behind a signed token. Core's delivery
     * API accepts `preview=1` plus `preview_token`; the presence of either marks
     * the response as preview output, valid token or not — an invalid one is
     * rejected before render, and treating it as preview here only ever errs
     * toward noindex.
     */
    public function isPreview(): bool
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app->make(Request::class);

        if ($request->query('preview_token') !== null) {
            return true;
        }

        return in_array($request->query('preview'), ['1', 1, 'true', true], true);
    }

    public function isMaintenance(): bool
    {
        return $this->app->isDownForMaintenance();
    }
}
