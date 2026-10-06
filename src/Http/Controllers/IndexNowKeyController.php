<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Http\Response;
use Magna\Seo\Integrations\IndexNow;

/**
 * Serves the IndexNow key verification file at /{key}.txt. IndexNow fetches this
 * to confirm ownership before accepting submissions. Returns 404 for any key that
 * is not the configured one, checked in constant time.
 */
final class IndexNowKeyController
{
    public function __invoke(string $key, IndexNow $indexNow): Response
    {
        abort_unless($indexNow->keyFileMatches($key), 404);

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
