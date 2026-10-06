<?php

declare(strict_types=1);

namespace Magna\Seo\Contracts;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;

/**
 * A registered origin of indexable content (pages, blog posts, docs, …).
 *
 * Implementing and registering this interface into the
 * {@see SeoSourceRegistry} is the entire integration surface
 * for a content plugin: with one class and zero edits to the SEO plugin, its
 * content gains meta, sitemap entries and scan coverage.
 */
interface SeoSubjectSource
{
    /**
     * Unique, stable, URL-safe handle for this source, e.g. "page" or "docs".
     * Doubles as the sitemap child-file discriminator.
     */
    public function handle(): string;

    /**
     * Human-readable label for admin surfaces.
     */
    public function label(): string;

    /**
     * Resolve a single subject by its source-scoped id, or null when it does not
     * exist or is not publicly renderable.
     */
    public function resolve(string $id): ?SeoSubject;

    /**
     * Stream every publicly indexable subject in bounded batches, so sitemap and
     * scan builds never hold the whole content set in memory. Implementations
     * MUST chunk their query (cursor/paginate), not eager-load everything.
     *
     * @param  callable(list<SeoSubject>): void  $callback  Invoked once per batch.
     */
    public function chunk(callable $callback, int $size = 500): void;

    /**
     * The Eloquent model class SEO meta attaches to for this source, or null when
     * the source is not Eloquent-backed (meta is then keyed by subject only).
     *
     * @return class-string<Model>|null
     */
    public function modelClass(): ?string;
}
