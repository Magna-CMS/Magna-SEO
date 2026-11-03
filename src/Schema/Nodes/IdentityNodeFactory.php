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
 * An organisation's logo is an ImageObject with its own @id, so other nodes
 * (and other pages' graphs) can point at the same logo rather than repeat it.
 * A person has no logo; the setting is ignored for one.
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

        $logo = $context->logo;
        if (! $isPerson && $logo !== null) {
            $image = [
                '@type' => 'ImageObject',
                '@id' => $context->baseUrl.'/#logo',
                'url' => $logo->url,
                'contentUrl' => $logo->url,
            ];
            if ($logo->width !== null) {
                $image['width'] = $logo->width;
            }
            if ($logo->height !== null) {
                $image['height'] = $logo->height;
            }
            $image['caption'] = $node['name'];

            $node['logo'] = $image;
            $node['image'] = ['@id' => $image['@id']];
        }

        return [$node];
    }
}
