<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * A single ImageObject node for the subject's primary image, referenced by @id
 * from the WebPage and Article nodes so the image data lives in one place.
 */
final class ImageNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $image = $context->image;
        if ($image === null || $context->imageId === null) {
            return [];
        }

        $node = [
            '@type' => 'ImageObject',
            '@id' => $context->imageId,
            'url' => $image->url,
        ];

        if ($image->width !== null) {
            $node['width'] = $image->width;
        }
        if ($image->height !== null) {
            $node['height'] = $image->height;
        }
        if ($image->alt !== null && $image->alt !== '') {
            $node['caption'] = $image->alt;
        }

        return [$node];
    }
}
