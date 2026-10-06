<?php

declare(strict_types=1);

namespace Magna\Seo\Import;

/**
 * One post parsed from a WordPress WXR export: enough to match it to a Magna
 * model (title/slug/link/status) plus its raw post meta for {@see SeoMetaMapper}.
 */
final readonly class WxrPost
{
    /**
     * @param  array<string, string>  $postmeta  meta_key => meta_value
     */
    public function __construct(
        public string $title,
        public string $slug,
        public string $link,
        public string $status,
        public array $postmeta,
    ) {}
}
