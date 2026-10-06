<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use Magna\Seo\Subjects\SeoSubject;

/**
 * The plain text a content-analysis check works over: the focus keyword and the
 * resolved title / description / slug / body. Kept free of the SeoSubject model
 * so checks stay trivially unit-testable.
 */
final readonly class AnalysisInput
{
    /**
     * @param  string  $html  Body markup, when the source has it. Structural checks
     *                        (headings, links, image alt text) need markup; the
     *                        text-only checks work without it.
     * @param  string  $siteUrl  Used to tell internal links from outbound ones.
     */
    /** Parsed structure of the body markup, built once so checks share the work. */
    public DocumentOutline $outline;

    public function __construct(
        public string $keyword,
        public string $title,
        public string $description,
        public string $slug,
        public string $plainText,
        public string $html = '',
        public string $siteUrl = '',
    ) {
        $this->outline = new DocumentOutline($html, $siteUrl);
    }

    /**
     * Build an input from a subject, its resolved SEO description and a focus
     * keyword. The slug is the last path segment of the subject URL.
     *
     * A source that wants the structural checks to run supplies its body markup
     * as `raw['html']`; without it those checks report "not available" rather
     * than guessing from stripped text.
     */
    public static function forSubject(SeoSubject $subject, string $description, string $keyword): self
    {
        $path = (string) parse_url($subject->url, PHP_URL_PATH);
        $slug = ($path !== '' && $path !== '/') ? basename($path) : '';
        $html = $subject->raw['html'] ?? null;

        return new self(
            keyword: trim($keyword),
            title: $subject->title,
            description: $description,
            slug: $slug,
            plainText: $subject->plainText,
            html: is_string($html) ? $html : '',
            siteUrl: $subject->url,
        );
    }

    public function hasKeyword(): bool
    {
        return $this->keyword !== '';
    }

    public function wordCount(): int
    {
        return (int) preg_match_all('/\S+/u', $this->plainText);
    }
}
