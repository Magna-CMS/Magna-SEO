<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Models\SeoMeta;

/**
 * The single read/write boundary for {@see SeoMeta}. Collection callers (sitemaps,
 * scans) MUST use {@see self::forMany()} — a per-subject lazy load turns a large
 * sitemap into an N-query timeout.
 */
final class SeoMetaRepository
{
    public function for(string $type, string $id): ?SeoMeta
    {
        return SeoMeta::query()
            ->where('seoable_type', $type)
            ->where('seoable_id', $id)
            ->first();
    }

    public function forModel(Model $model): ?SeoMeta
    {
        return $this->for($model->getMorphClass(), (string) $model->getKey());
    }

    /**
     * Bulk-load meta for many (type, id) pairs in a bounded number of queries —
     * one per distinct type, each an indexed whereIn. Returns a map keyed by
     * {@see self::key()} so callers can look each subject up in O(1).
     *
     * @param  iterable<array{0: string, 1: string}>  $pairs
     * @return array<string, SeoMeta>
     */
    public function forMany(iterable $pairs): array
    {
        /** @var array<string, list<string>> $idsByType */
        $idsByType = [];
        foreach ($pairs as [$type, $id]) {
            $idsByType[$type][] = (string) $id;
        }

        $map = [];
        foreach ($idsByType as $type => $ids) {
            SeoMeta::query()
                ->where('seoable_type', $type)
                ->whereIn('seoable_id', array_values(array_unique($ids)))
                ->get()
                ->each(function (SeoMeta $meta) use (&$map, $type): void {
                    $map[$this->key($type, (string) $meta->getAttribute('seoable_id'))] = $meta;
                });
        }

        return $map;
    }

    /**
     * Create the meta row for a subject if absent, then apply the given
     * attributes. The morph keys are set explicitly (never mass-assigned), so
     * only whitelisted SEO columns are writable from caller input.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(string $type, string $id, array $attributes): SeoMeta
    {
        $meta = $this->for($type, $id);

        if ($meta === null) {
            $meta = new SeoMeta;
            $meta->setAttribute('seoable_type', $type);
            $meta->setAttribute('seoable_id', $id);
        }

        $meta->fill($attributes);
        $meta->save();

        return $meta;
    }

    /**
     * Stable composite key for the {@see self::forMany()} map. The NUL separator
     * cannot occur in a class name or id, so keys never collide.
     */
    public function key(string $type, string $id): string
    {
        return $type."\0".$id;
    }
}
