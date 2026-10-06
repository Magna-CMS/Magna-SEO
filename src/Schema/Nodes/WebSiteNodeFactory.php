<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Schema\SchemaContext;

/**
 * The site-level WebSite node. Only emitted when a base URL is configured, since
 * without one it has no stable @id or url to anchor the rest of the graph to.
 */
final class WebSiteNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        if ($context->baseUrl === '') {
            return [];
        }

        $node = [
            '@type' => 'WebSite',
            '@id' => $context->websiteId,
            'url' => $context->baseUrl,
            'inLanguage' => $context->subject->locale,
        ];

        if ($context->settings->site_name !== '') {
            $node['name'] = $context->settings->site_name;
        }

        if ($context->hasIdentity) {
            $node['publisher'] = ['@id' => $context->identityId];
        }

        $search = $this->searchAction($context->settings->search_url_template);
        if ($search !== null) {
            $node['potentialAction'] = [$search];
        }

        return [$node];
    }

    /**
     * The sitelinks search box, advertised only when the site actually has a
     * search URL configured. The template must contain the placeholder Google
     * substitutes into; without it the action would point every search at a
     * fixed page, which is worse than emitting nothing.
     *
     * @return array<string, mixed>|null
     */
    private function searchAction(string $template): ?array
    {
        $template = trim($template);

        if ($template === '' || ! str_contains($template, '{search_term_string}')) {
            return null;
        }

        $scheme = strtolower((string) parse_url($template, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => $template,
            ],
            'query-input' => 'required name=search_term_string',
        ];
    }
}
