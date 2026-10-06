<?php

declare(strict_types=1);

namespace Magna\Seo\Head;

/**
 * The resolved, render-target-agnostic head of a document: a title, `<meta>`
 * entries split by their attribute (`name` vs `property`), `<link>` relations,
 * hreflang alternates, and JSON-LD graph nodes. Values are stored raw and
 * unescaped — escaping is the renderer's job, done once, in the correct context.
 * This single object feeds both the HTML renderer and the delivery-API
 * serialiser, so every surface emits identical meta.
 */
final readonly class HeadPayload
{
    /**
     * @param  array<string, string>  $metaNames  <meta name="k" content="v">
     * @param  array<string, string>  $metaProperties  <meta property="k" content="v">
     * @param  array<string, string>  $links  <link rel="k" href="v">
     * @param  list<array{hreflang: string, href: string}>  $alternates  <link rel="alternate" hreflang href>
     * @param  list<array<string, mixed>>  $jsonLd  schema.org graph nodes
     */
    public function __construct(
        public string $title,
        public array $metaNames = [],
        public array $metaProperties = [],
        public array $links = [],
        public array $alternates = [],
        public array $jsonLd = [],
    ) {}

    /**
     * Return a copy carrying the given JSON-LD graph nodes. Keeps meta resolution
     * and schema generation as separate steps composing one immutable payload.
     *
     * @param  list<array<string, mixed>>  $jsonLd
     */
    public function withJsonLd(array $jsonLd): self
    {
        return new self(
            title: $this->title,
            metaNames: $this->metaNames,
            metaProperties: $this->metaProperties,
            links: $this->links,
            alternates: $this->alternates,
            jsonLd: $jsonLd,
        );
    }

    /**
     * Structured form for the delivery API, letting a headless frontend drive its
     * own head primitives instead of injecting raw HTML.
     *
     * @return array{title: string, meta: array<string, string>, properties: array<string, string>, links: array<string, string>, alternates: list<array{hreflang: string, href: string}>, jsonld: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'meta' => $this->metaNames,
            'properties' => $this->metaProperties,
            'links' => $this->links,
            'alternates' => $this->alternates,
            'jsonld' => $this->jsonLd,
        ];
    }
}
