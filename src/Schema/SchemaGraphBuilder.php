<?php

declare(strict_types=1);

namespace Magna\Seo\Schema;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Assembles a single schema.org `@graph` document from the registered node
 * factories. It resolves the shared @id values once and hands them to every
 * factory through a {@see SchemaContext}, so the nodes cross-reference each other
 * (WebPage isPartOf WebSite, Article mainEntityOfPage WebPage, …) to form one
 * connected graph rather than a pile of disconnected objects.
 *
 * Reads the already-resolved head so the structured data can never disagree with
 * the meta tags. Returns null when nothing meaningful can be emitted.
 */
final class SchemaGraphBuilder
{
    /**
     * @param  list<SchemaNodeFactory>  $factories
     */
    public function __construct(
        private readonly array $factories,
        private readonly string $baseUrl,
    ) {}

    /**
     * @return array{'@context': string, '@graph': list<array<string, mixed>>}|null
     */
    public function build(SeoSubject $subject, HeadPayload $head, SeoSettings $settings): ?array
    {
        $context = $this->context($subject, $head, $settings);

        $nodes = [];
        foreach ($this->factories as $factory) {
            foreach ($factory->make($context) as $node) {
                $nodes[] = $node;
            }
        }

        if ($nodes === []) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    private function context(SeoSubject $subject, HeadPayload $head, SeoSettings $settings): SchemaContext
    {
        $base = rtrim($this->baseUrl, '/');

        $canonical = $head->links['canonical'] ?? null;
        $description = $head->metaNames['description'] ?? null;
        $image = $subject->primaryImage();

        $imageId = null;
        if ($image !== null) {
            $imageId = $canonical !== null ? $canonical.'#primaryimage' : $image->url;
        }

        return new SchemaContext(
            subject: $subject,
            settings: $settings,
            baseUrl: $base,
            websiteId: $this->fragmentId($base, 'website'),
            identityId: $this->fragmentId($base, 'identity'),
            hasIdentity: $this->hasIdentity($settings),
            canonical: $canonical,
            webPageId: $canonical !== null ? $canonical.'#webpage' : null,
            description: $description,
            image: $image,
            imageId: $imageId,
        );
    }

    private function fragmentId(string $base, string $fragment): string
    {
        return ($base !== '' ? $base : '').'/#'.$fragment;
    }

    private function hasIdentity(SeoSettings $settings): bool
    {
        return $settings->knowledge_graph_type === 'person'
            ? $settings->person_name !== ''
            : $settings->organization_name !== '';
    }
}
