<?php

declare(strict_types=1);

namespace Magna\Seo\Subjects;

use DateTimeImmutable;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Enums\SubjectType;

/**
 * An immutable, render-agnostic description of one indexable thing — a page, a
 * blog post, a doc, a taxonomy term. Content plugins produce these from their
 * own models via a {@see SeoSubjectSource}; the SEO meta
 * pipeline consumes them without ever knowing the underlying model type.
 *
 * It is deliberately a value object rather than an interface models implement:
 * a source may be non-Eloquent, and different sources (Entry, DocPage, …) share
 * no base class, so a plain carrier is the only thing they can all populate.
 */
final readonly class SeoSubject
{
    /**
     * @param  string  $key  Stable, source-scoped identity, e.g. "page:01H…".
     * @param  string  $url  Absolute canonical URL, built by the source itself.
     * @param  string  $plainText  Body with tags stripped — feeds analysis and the description fallback.
     * @param  bool  $indexable  The source's own verdict, before per-entity SEO overrides.
     * @param  array<string, string>  $alternates  locale => absolute URL, for hreflang.
     * @param  list<SeoImage>  $images  Ordered; the first is the preferred social image.
     * @param  list<SeoCrumb>  $breadcrumbs  Root-first trail.
     * @param  array<string, mixed>  $raw  Source-specific extras (e.g. faq blocks, feed URL) read by opt-in consumers.
     * @param  string|null  $modelId  Primary key of the backing model, when the source has one.
     *                                Lets collection callers bulk-load per-entity SEO overrides
     *                                (see SeoMetaRepository::forMany) instead of querying per subject.
     */
    public function __construct(
        public string $key,
        public SubjectType $type,
        public string $url,
        public string $title,
        public string $locale,
        public bool $indexable,
        public DateTimeImmutable $updatedAt,
        public string $plainText = '',
        public ?string $excerpt = null,
        public ?DateTimeImmutable $publishedAt = null,
        public array $alternates = [],
        public array $images = [],
        public array $breadcrumbs = [],
        public ?SeoAuthor $author = null,
        public array $raw = [],
        public ?string $modelId = null,
    ) {}

    /**
     * A copy with its indexability changed — used to apply a content type's
     * blanket exclusion without the source having to know about it.
     */
    public function withIndexable(bool $indexable): self
    {
        return new self(
            key: $this->key,
            type: $this->type,
            url: $this->url,
            title: $this->title,
            locale: $this->locale,
            indexable: $indexable,
            updatedAt: $this->updatedAt,
            plainText: $this->plainText,
            excerpt: $this->excerpt,
            publishedAt: $this->publishedAt,
            alternates: $this->alternates,
            images: $this->images,
            breadcrumbs: $this->breadcrumbs,
            author: $this->author,
            raw: $this->raw,
            modelId: $this->modelId,
        );
    }

    /**
     * The preferred social/preview image, if the source supplied any.
     */
    public function primaryImage(): ?SeoImage
    {
        return $this->socialImage(null);
    }

    /**
     * The image for one network — `null` for the general/Open Graph image,
     * "twitter" for a Twitter-specific one. Falls back to the first image the
     * subject carries, so a network-specific override is optional everywhere.
     */
    public function socialImage(?string $role = null): ?SeoImage
    {
        foreach ($this->images as $image) {
            if ($image->role === $role) {
                return $image;
            }
        }

        return $this->images[0] ?? null;
    }

    /**
     * A copy with its image set replaced — used to fold in a resolved override or
     * site-default social image without the source having to know about either.
     *
     * @param  list<SeoImage>  $images
     */
    public function withImages(array $images): self
    {
        return new self(
            key: $this->key,
            type: $this->type,
            url: $this->url,
            title: $this->title,
            locale: $this->locale,
            indexable: $this->indexable,
            updatedAt: $this->updatedAt,
            plainText: $this->plainText,
            excerpt: $this->excerpt,
            publishedAt: $this->publishedAt,
            alternates: $this->alternates,
            images: $images,
            breadcrumbs: $this->breadcrumbs,
            author: $this->author,
            raw: $this->raw,
            modelId: $this->modelId,
        );
    }
}
