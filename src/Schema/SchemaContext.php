<?php

declare(strict_types=1);

namespace Magna\Seo\Schema;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Everything a {@see SchemaNodeFactory} needs to build its
 * node, pre-resolved once by {@see SchemaGraphBuilder} so node types agree on the
 * same @id values and never recompute them. Values are already the final,
 * resolved meta (description/canonical/image come from the {@see HeadPayload}),
 * keeping schema output consistent with the tags in the head.
 */
final readonly class SchemaContext
{
    public function __construct(
        public SeoSubject $subject,
        public SeoSettings $settings,
        public string $baseUrl,
        public string $websiteId,
        public string $identityId,
        public bool $hasIdentity,
        public ?string $canonical,
        public ?string $webPageId,
        public ?string $description,
        public ?SeoImage $image,
        public ?string $imageId,
    ) {}
}
