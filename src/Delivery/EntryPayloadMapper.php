<?php

declare(strict_types=1);

namespace Magna\Seo\Delivery;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Url\EntryUrlResolver;
use Throwable;

/**
 * Turns an already-built delivery payload into a {@see SeoSubject}, reading only
 * the array it is handed — never re-querying the database, so decorating a list
 * of N entries adds zero queries. Which payload keys carry the title, excerpt,
 * body and image is configurable per install (see config/seo.php), because a
 * content entry has no fixed schema.
 */
final class EntryPayloadMapper
{
    /**
     * @param  list<string>  $titleFields
     * @param  list<string>  $excerptFields
     * @param  list<string>  $bodyFields
     * @param  list<string>  $imageFields
     */
    public function __construct(
        private readonly array $titleFields,
        private readonly array $excerptFields,
        private readonly array $bodyFields,
        private readonly array $imageFields,
        private readonly string $defaultLocale = 'en',
        private readonly ?EntryUrlResolver $urls = null,
        private readonly EntryIndexability $indexability = new EntryIndexability,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(string $contentType, array $payload): ?SeoSubject
    {
        $title = $this->firstString($payload, $this->titleFields);
        if ($title === null) {
            // Nothing meaningful to describe — let the decorator skip this entry.
            return null;
        }

        $id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        $excerpt = $this->firstString($payload, $this->excerptFields);
        $body = $this->firstString($payload, $this->bodyFields) ?? '';
        $url = $this->urls?->forPayload($contentType, $payload) ?? '';

        return new SeoSubject(
            key: 'entry:'.$contentType.':'.$id,
            type: SubjectType::Page,
            url: $url,
            title: $title,
            locale: $this->localeFrom($payload),
            indexable: $this->indexability->isIndexable($payload, $url !== ''),
            updatedAt: $this->dateFrom($payload, 'updated_at') ?? new DateTimeImmutable,
            plainText: $this->plainText($body),
            excerpt: $excerpt,
            publishedAt: $this->dateFrom($payload, 'published_at'),
            images: $this->images($payload) ?: $this->imagesInBody($body),
            // The content type lets per-type SEO defaults apply, and the body
            // markup lets the structural checks run on entries too.
            raw: ['content_type' => $contentType, 'html' => $body],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    private function firstString(array $payload, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<SeoImage>
     */
    private function images(array $payload): array
    {
        foreach ($this->imageFields as $field) {
            $value = $payload[$field] ?? null;

            $image = $this->toImage($value)
                ?? (is_array($value) ? $this->firstImageInList($value) : null);

            if ($image !== null) {
                return [$image];
            }
        }

        return [];
    }

    /**
     * Last rung of the social-image fallback chain: the first <img> in the body.
     * No dimensions are available from markup alone, so this is strictly worse
     * than a media-field image — hence it is only reached when every configured
     * image field came up empty.
     *
     * @return list<SeoImage>
     */
    private function imagesInBody(string $html): array
    {
        if ($html === '' || ! preg_match('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $html, $matches)) {
            return [];
        }

        $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // Reject data: and other non-fetchable schemes — a social crawler cannot
        // use them, and an inline payload would bloat every response.
        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return [];
        }

        return [new SeoImage(url: $url)];
    }

    /**
     * @param  array<array-key, mixed>  $list
     */
    private function firstImageInList(array $list): ?SeoImage
    {
        foreach ($list as $item) {
            $image = $this->toImage($item);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private function toImage(mixed $value): ?SeoImage
    {
        if (! is_array($value)) {
            return null;
        }

        $url = $value['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return null;
        }

        return new SeoImage(
            url: $url,
            id: is_string($value['id'] ?? null) ? $value['id'] : null,
            width: is_int($value['width'] ?? null) ? $value['width'] : null,
            height: is_int($value['height'] ?? null) ? $value['height'] : null,
            alt: is_string($value['alt'] ?? null) ? $value['alt'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function localeFrom(array $payload): string
    {
        $locale = $payload['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : $this->defaultLocale;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dateFrom(array $payload, string $key): ?DateTimeImmutable
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }
}
