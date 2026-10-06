<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Http\Response;
use Magna\Seo\Llms\LlmsTxtGenerator;
use Magna\Seo\Settings\SeoSettings;

/**
 * Serves `/llms.txt` and `/llms-full.txt`.
 *
 * Both are markdown served as text/plain, which is what the convention specifies
 * and what a model fetching the URL can use without a parser. A 404 when the
 * feature is off is deliberate: an empty file would read as "this site has no
 * content" rather than "this site does not publish one".
 */
final class LlmsTxtController
{
    public function index(LlmsTxtGenerator $generator): Response
    {
        abort_unless(SeoSettings::get()->llms_txt_enabled, 404);

        return $this->markdown($generator->index());
    }

    public function full(LlmsTxtGenerator $generator): Response
    {
        $settings = SeoSettings::get();

        abort_unless($settings->llms_txt_enabled && $settings->llms_full_enabled, 404);

        return $this->markdown($generator->full());
    }

    private function markdown(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
