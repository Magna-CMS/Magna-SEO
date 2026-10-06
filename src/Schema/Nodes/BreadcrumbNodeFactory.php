<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * A BreadcrumbList built from the subject's trail. Emitted only when the source
 * supplied breadcrumbs, so sources without a hierarchy (or the delivery API,
 * which has no trail) simply omit it.
 */
final class BreadcrumbNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $crumbs = $context->subject->breadcrumbs;
        if ($crumbs === []) {
            return [];
        }

        $items = [];
        $position = 1;
        foreach ($crumbs as $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb->label,
            ];
            if ($crumb->url !== null) {
                $item['item'] = $crumb->url;
            }
            $items[] = $item;
            $position++;
        }

        $node = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        if ($context->canonical !== null) {
            $node['@id'] = $context->canonical.'#breadcrumb';
        }

        return [$node];
    }
}
