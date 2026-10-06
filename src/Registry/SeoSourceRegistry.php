<?php

declare(strict_types=1);

namespace Magna\Seo\Registry;

use InvalidArgumentException;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Subjects\SeoSubject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Process-wide registry of content sources. Bound as a singleton so
 * registrations made by content plugins during boot accumulate for the whole
 * request/worker lifetime.
 *
 * Registration is fail-fast (a duplicate or empty handle is a programming error
 * surfaced at boot), but resolution is fail-safe: a missing or throwing source
 * degrades to null rather than propagating an exception into a page render.
 */
final class SeoSourceRegistry
{
    /** @var array<string, SeoSubjectSource> */
    private array $sources = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @throws InvalidArgumentException on an empty or already-registered handle.
     */
    public function register(SeoSubjectSource $source): void
    {
        $handle = $source->handle();

        if ($handle === '') {
            throw new InvalidArgumentException('An SEO subject source must declare a non-empty handle.');
        }

        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $handle) || preg_match('/-\d+$/', $handle) === 1) {
            // The handle becomes a sitemap file name, where a trailing "-<digits>"
            // is the page number: "sitemap-news-2.xml" must mean page 2 of "news",
            // never page 1 of a source called "news-2".
            throw new InvalidArgumentException(
                "SEO subject source handle \"{$handle}\" must be lowercase, dash-separated, and must not end in a number.",
            );
        }

        if (isset($this->sources[$handle])) {
            throw new InvalidArgumentException(
                "An SEO subject source with handle \"{$handle}\" is already registered.",
            );
        }

        $this->sources[$handle] = $source;
    }

    public function has(string $handle): bool
    {
        return isset($this->sources[$handle]);
    }

    public function get(string $handle): ?SeoSubjectSource
    {
        return $this->sources[$handle] ?? null;
    }

    /**
     * All registered sources, ordered deterministically by handle so sitemap
     * indexes and admin lists are stable across requests.
     *
     * @return array<string, SeoSubjectSource>
     */
    public function all(): array
    {
        $sources = $this->sources;
        ksort($sources);

        return $sources;
    }

    /**
     * Resolve a subject without ever letting a source fault reach the caller: an
     * unknown handle returns null, and a source that throws is logged and
     * returns null. Callers treat null as "no meta".
     */
    public function resolve(string $handle, string $id): ?SeoSubject
    {
        $source = $this->sources[$handle] ?? null;

        if ($source === null) {
            return null;
        }

        try {
            return $source->resolve($id);
        } catch (Throwable $e) {
            $this->logger->warning('SEO subject resolution failed.', [
                'handle' => $handle,
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
