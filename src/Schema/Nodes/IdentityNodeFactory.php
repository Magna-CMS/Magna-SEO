<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * The site's publishing identity — an Organization or a Person, per
 * SeoSettings::knowledge_graph_type. Skipped entirely when no name is configured,
 * so the graph never carries an empty, meaningless identity node.
 *
 * The organisation logo needs a media-URL resolver and is added in a later stage.
 */
final class IdentityNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        if (! $context->hasIdentity) {
            return [];
        }

        $settings = $context->settings;
        $isPerson = $settings->knowledge_graph_type === 'person';

        $node = [
            '@type' => $isPerson ? 'Person' : 'Organization',
            '@id' => $context->identityId,
            'name' => $isPerson ? $settings->person_name : $settings->organization_name,
        ];

        if ($context->baseUrl !== '') {
            $node['url'] = $context->baseUrl;
        }

        $sameAs = array_values(array_filter(
            $settings->social_profiles,
            static fn (string $url): bool => trim($url) !== '',
        ));
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        return [$node];
    }
}
