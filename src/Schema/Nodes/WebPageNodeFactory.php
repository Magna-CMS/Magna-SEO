<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * The WebPage node for the current subject. Always emitted (every subject is a
 * page), linking up to the WebSite and down to its primary image when present.
 *
 * @id and url are omitted when no canonical URL is known (e.g. an entry served
 * through the delivery API on a headless install), which is valid JSON-LD.
 */
final class WebPageNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $subject = $context->subject;

        $node = ['@type' => 'WebPage'];

        if ($context->webPageId !== null) {
            $node['@id'] = $context->webPageId;
        }
        if ($context->canonical !== null) {
            $node['url'] = $context->canonical;
        }

        $node['name'] = $subject->title;
        $node['inLanguage'] = $subject->locale;

        if ($context->description !== null) {
            $node['description'] = $context->description;
        }

        if ($context->baseUrl !== '') {
            $node['isPartOf'] = ['@id' => $context->websiteId];
        }

        if ($subject->publishedAt !== null) {
            $node['datePublished'] = $subject->publishedAt->format('c');
        }
        $node['dateModified'] = $subject->updatedAt->format('c');

        if ($context->imageId !== null) {
            $node['primaryImageOfPage'] = ['@id' => $context->imageId];
        }

        return [$node];
    }
}
