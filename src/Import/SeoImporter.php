<?php

declare(strict_types=1);

namespace Magna\Seo\Import;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Support\SeoMetaRepository;

/**
 * Applies imported SEO meta (mapped from Yoast/Rank Math) onto a resolved Magna
 * model, writing through the same repository as everything else so the morph
 * keys stay safe. The caller resolves which model a WordPress post maps to
 * (usually by slug); this only performs the mapping and the write.
 */
final class SeoImporter
{
    public function __construct(
        private readonly SeoMetaMapper $mapper,
        private readonly SeoMetaRepository $repository,
    ) {}

    /**
     * Import a post's meta onto a target model. Returns true when something was
     * written, false when the meta had no supported/usable fields.
     *
     * @param  array<string, string>  $postmeta
     */
    public function import(array $postmeta, Model $target): bool
    {
        $mapped = $this->mapper->map($postmeta);

        if ($mapped === null || $mapped === []) {
            return false;
        }

        $this->repository->upsert($target->getMorphClass(), (string) $target->getKey(), $mapped);

        return true;
    }
}
