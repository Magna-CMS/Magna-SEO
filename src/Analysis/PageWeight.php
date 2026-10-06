<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * How heavy a page's markup is, measured from the markup itself.
 *
 * Page speed is a ranking signal, and the usual advice — "run PageSpeed
 * Insights" — needs a network call per URL and a live site. Most of the damage
 * is visible in the source before anything is deployed: a body that ships
 * hundreds of kilobytes of HTML, an inline script the size of a library, a DOM
 * deep enough to stall layout, images with no dimensions so everything below
 * them jumps as they load.
 *
 * This measures those, with no network, from the same `raw['html']` the
 * structural analysis already parses.
 */
final readonly class PageWeight
{
    public function __construct(
        public int $htmlBytes,
        public int $inlineScriptBytes,
        public int $inlineStyleBytes,
        public int $domNodes,
        public int $imageCount,
        public int $imagesWithoutDimensions,
    ) {}

    public static function of(string $html, DocumentOutline $outline): self
    {
        return new self(
            htmlBytes: strlen($html),
            inlineScriptBytes: self::taggedBytes($html, 'script'),
            inlineStyleBytes: self::taggedBytes($html, 'style'),
            domNodes: self::countNodes($html),
            imageCount: count($outline->images),
            imagesWithoutDimensions: self::imagesWithoutDimensions($html),
        );
    }

    /**
     * Everything the browser must download before it can render this markup:
     * the HTML plus whatever is inlined into it.
     */
    public function totalBytes(): int
    {
        return $this->htmlBytes;
    }

    public function humanSize(): string
    {
        $kb = $this->htmlBytes / 1024;

        return $kb >= 1024
            ? number_format($kb / 1024, 1).' MB'
            : number_format($kb, 0).' KB';
    }

    /**
     * Bytes inside a given tag, e.g. every inline <script> body added together.
     * External src/href references are not counted — they are a separate request
     * and this measures what the document itself carries.
     */
    private static function taggedBytes(string $html, string $tag): int
    {
        if (! preg_match_all('#<'.$tag.'\b[^>]*>(.*?)</'.$tag.'>#is', $html, $matches)) {
            return 0;
        }

        $bytes = 0;
        foreach ($matches[1] as $body) {
            $bytes += strlen($body);
        }

        return $bytes;
    }

    /**
     * Element count, approximated by counting opening tags. Exact enough for a
     * threshold — the difference between a 900-node page and a 4 000-node one is
     * not a rounding question.
     */
    private static function countNodes(string $html): int
    {
        return (int) preg_match_all('/<[a-zA-Z][a-zA-Z0-9-]*(\s|>|\/)/', $html);
    }

    /**
     * Images missing width or height. Without both, the browser cannot reserve
     * space and everything below the image shifts when it loads — the layout
     * shift Core Web Vitals penalises.
     */
    private static function imagesWithoutDimensions(string $html): int
    {
        if (! preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            return 0;
        }

        $missing = 0;
        foreach ($matches[0] as $tag) {
            $hasWidth = preg_match('/\bwidth\s*=/i', $tag) === 1;
            $hasHeight = preg_match('/\bheight\s*=/i', $tag) === 1;

            if (! $hasWidth || ! $hasHeight) {
                $missing++;
            }
        }

        return $missing;
    }
}
