<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Subjects\SeoSubject;
use RuntimeException;

/**
 * In-memory {@see SeoSubjectSource} for tests, so the SEO plugin's own tests
 * never require another content plugin to be installed.
 */
final class FakeSeoSubjectSource implements SeoSubjectSource
{
    /**
     * @param  array<string, SeoSubject>  $subjects  keyed by id
     */
    public function __construct(
        private readonly string $handle = 'fake',
        private array $subjects = [],
        private readonly bool $throwOnResolve = false,
        private readonly bool $throwOnChunk = false,
        private readonly ?string $modelClass = null,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    /**
     * Swap the backing content, so a test can prove a cached build is stale until
     * it is purged.
     *
     * @param  array<string, SeoSubject>  $subjects
     */
    public function replace(array $subjects): void
    {
        $this->subjects = $subjects;
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function resolve(string $id): ?SeoSubject
    {
        if ($this->throwOnResolve) {
            throw new RuntimeException('resolve failed');
        }

        return $this->subjects[$id] ?? null;
    }

    public function chunk(callable $callback, int $size = 500): void
    {
        if ($this->throwOnChunk) {
            throw new RuntimeException('chunk failed');
        }

        foreach (array_chunk(array_values($this->subjects), max(1, $size)) as $batch) {
            $callback($batch);
        }
    }

    public function modelClass(): ?string
    {
        /** @var class-string<Model>|null */
        return $this->modelClass;
    }
}
