<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The structure of a page's body markup: its headings, links, images, paragraphs
 * and sentences. Parsed once and shared by every structural analysis check.
 *
 * Parsing is done with DOMDocument rather than regular expressions — editor
 * output is real HTML with nesting and attributes, and a regex over it produces
 * confidently wrong advice. Network access and external entities are disabled:
 * this markup is author-supplied, and analysis must never make a request.
 */
final class DocumentOutline
{
    /** @var list<array{level: int, text: string}> */
    public readonly array $headings;

    /** @var list<array{href: string, internal: bool}> */
    public readonly array $links;

    /** @var list<array{alt: string, decorative: bool}> */
    public readonly array $images;

    /** @var list<string> */
    public readonly array $paragraphs;

    public readonly bool $hasMarkup;

    public function __construct(string $html, private readonly string $siteUrl = '')
    {
        $html = trim($html);
        $this->hasMarkup = $html !== '' && str_contains($html, '<');

        if (! $this->hasMarkup) {
            $this->headings = [];
            $this->links = [];
            $this->images = [];
            $this->paragraphs = $html === '' ? [] : [$html];

            return;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Author markup is a fragment, not a document; the meta charset keeps
        // DOMDocument from mangling UTF-8 text.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $this->headings = $this->readHeadings($xpath);
        $this->links = $this->readLinks($xpath);
        $this->images = $this->readImages($xpath);
        $this->paragraphs = $this->readParagraphs($xpath);
    }

    public function headingsAtLevel(int $level): int
    {
        return count(array_filter($this->headings, static fn (array $h): bool => $h['level'] === $level));
    }

    /**
     * @return list<string>
     */
    public function headingTexts(): array
    {
        return array_map(static fn (array $h): string => $h['text'], $this->headings);
    }

    public function internalLinkCount(): int
    {
        return count(array_filter($this->links, static fn (array $l): bool => $l['internal']));
    }

    public function outboundLinkCount(): int
    {
        return count(array_filter($this->links, static fn (array $l): bool => ! $l['internal']));
    }

    /**
     * Images that need alt text. A `role="presentation"` or explicitly empty alt
     * marks a decorative image, which correctly has none — counting those as
     * failures would push authors into writing noise for screen readers.
     *
     * @return list<array{alt: string, decorative: bool}>
     */
    public function meaningfulImages(): array
    {
        return array_values(array_filter($this->images, static fn (array $i): bool => ! $i['decorative']));
    }

    /**
     * Sentences of the body text. Splitting on terminal punctuation followed by
     * whitespace is imperfect (abbreviations split early) but stable and
     * language-agnostic enough for the length and voice heuristics.
     *
     * @return list<string>
     */
    public function sentences(string $plainText): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $plainText) ?? $plainText);

        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private function readHeadings(DOMXPath $xpath): array
    {
        $headings = [];

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $headings[] = [
                'level' => (int) substr($node->nodeName, 1),
                'text' => trim($node->textContent),
            ];
        }

        return $headings;
    }

    /**
     * @return list<array{href: string, internal: bool}>
     */
    private function readLinks(DOMXPath $xpath): array
    {
        $host = strtolower((string) parse_url($this->siteUrl, PHP_URL_HOST));
        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            // Anchors and non-navigational schemes are not links to anywhere.
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
                continue;
            }

            $linkHost = strtolower((string) parse_url($href, PHP_URL_HOST));

            $links[] = [
                'href' => $href,
                'internal' => $linkHost === '' || ($host !== '' && $linkHost === $host),
            ];
        }

        return $links;
    }

    /**
     * @return list<array{alt: string, decorative: bool}>
     */
    private function readImages(DOMXPath $xpath): array
    {
        $images = [];

        foreach ($xpath->query('//img') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $alt = trim($node->getAttribute('alt'));
            $decorative = $node->getAttribute('role') === 'presentation'
                || ($node->hasAttribute('alt') && $alt === '');

            $images[] = ['alt' => $alt, 'decorative' => $decorative];
        }

        return $images;
    }

    /**
     * @return list<string>
     */
    private function readParagraphs(DOMXPath $xpath): array
    {
        $paragraphs = [];

        foreach ($xpath->query('//p') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }
}
