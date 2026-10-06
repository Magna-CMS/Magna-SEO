<?php

declare(strict_types=1);

namespace Magna\Seo\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Response;
use Magna\Seo\Robots\AiCrawlers;
use Magna\Seo\Settings\SeoSettings;

/**
 * Serves a dynamic, environment-aware robots.txt: everything is disallowed
 * outside production so staging never gets indexed, and in production crawling is
 * allowed with a pointer to the sitemap index and, when published, to llms.txt.
 *
 * Production output also carries per-purpose rules for AI crawlers. Unlike
 * llms.txt — which is a convention no provider treats as an indexing signal —
 * these directives are the mechanism that actually decides whether an AI platform
 * may use the site, and each purpose is decided separately because "block AI"
 * conflates three different trades. See {@see AiCrawlers}.
 *
 * NOTE: a static public/robots.txt is served by the web server before PHP runs
 * and shadows this route. The `seo:robots` command backs that file out so this
 * endpoint can take effect.
 */
final class RobotsController
{
    public function __invoke(Application $app): Response
    {
        $settings = SeoSettings::get();
        $lines = ['User-agent: *'];

        if (! $app->isProduction()) {
            $lines[] = 'Disallow: /';

            return $this->text($lines);
        }

        $lines[] = 'Allow: /';

        foreach ($this->aiRules($settings) as $rule) {
            $lines[] = '';
            $lines[] = $rule;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.url('sitemap.xml');

        if ($settings->llms_txt_enabled) {
            // Not part of the robots.txt specification, so it is a comment: a
            // parser that does not know the key must not treat the line as a rule.
            $lines[] = '# llms.txt: '.url('llms.txt');
        }

        return $this->text($lines);
    }

    /**
     * One Disallow block per blocked AI crawler. Only blocks are emitted —
     * crawling is already allowed by the wildcard above, so restating it per
     * agent would add noise without changing behaviour.
     *
     * @return list<string>
     */
    private function aiRules(SeoSettings $settings): array
    {
        $blockedPurposes = array_filter([
            AiCrawlers::TRAINING => ! $settings->allow_ai_training,
            AiCrawlers::SEARCH => ! $settings->allow_ai_search,
            AiCrawlers::USER => ! $settings->allow_ai_user_fetch,
        ]);

        $lines = [];

        foreach (array_keys($blockedPurposes) as $purpose) {
            foreach (AiCrawlers::forPurpose($purpose) as $agent) {
                $lines[] = "User-agent: {$agent}\nDisallow: /";
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function text(array $lines): Response
    {
        return response(
            implode("\n", $lines)."\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
