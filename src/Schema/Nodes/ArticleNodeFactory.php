<?php

declare(strict_types=1);

namespace Magna\Seo\Schema\Nodes;

use Magna\Seo\Contracts\SchemaNodeFactory;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\TypeDefaults;
use Magna\Seo\Schema\SchemaContext;

/**
 * An Article node, emitted only for article-typed subjects. Links to the WebPage
 * as its mainEntityOfPage, to the site identity as publisher, and to the shared
 * image node, so the article does not duplicate data already in the graph.
 */
final class ArticleNodeFactory implements SchemaNodeFactory
{
    public function make(SchemaContext $context): array
    {
        $subject = $context->subject;

        // A source may name the subtype per page; a site may name one for the
        // whole content type. The page's own choice is the more specific one.
        $requested = $subject->raw['schema_type'] ?? TypeDefaults::schemaType($subject, $context->settings);
        $type = $this->schemaType($subject->type, $requested);

        if ($type === null) {
            return [];
        }

        $node = ['@type' => $type];

        if ($context->canonical !== null) {
            $node['@id'] = $context->canonical.'#article';
        }

        $node['headline'] = $subject->title;
        $node['inLanguage'] = $subject->locale;

        if ($context->description !== null) {
            $node['description'] = $context->description;
        }

        if ($subject->publishedAt !== null) {
            $node['datePublished'] = $subject->publishedAt->format('c');
        }
        $node['dateModified'] = $subject->updatedAt->format('c');

        if ($context->webPageId !== null) {
            $node['mainEntityOfPage'] = ['@id' => $context->webPageId];
        }

        if ($context->imageId !== null) {
            $node['image'] = ['@id' => $context->imageId];
        }

        if ($subject->author !== null) {
            $author = ['@type' => 'Person', 'name' => $subject->author->name];
            if ($subject->author->url !== null) {
                $author['url'] = $subject->author->url;
            }
            $node['author'] = $author;
        }

        if ($context->hasIdentity) {
            $node['publisher'] = ['@id' => $context->identityId];
        }

        return [$node];
    }

    /**
     * The subject's kind decides the subtype, but a source may name a more precise
     * one via `raw['schema_type']` (e.g. NewsArticle). Anything outside the
     * allowlist is ignored rather than trusted into the graph.
     */
    private function schemaType(SubjectType $type, mixed $requested): ?string
    {
        $allowed = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report'];

        if (is_string($requested) && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $type->articleSchemaType();
    }
}
