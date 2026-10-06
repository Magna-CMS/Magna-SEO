<?php

declare(strict_types=1);

namespace Magna\Seo\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parses a WordPress WXR (eXtended RSS) export into posts and their meta. XML is
 * loaded with network access disabled and without external-entity expansion, so a
 * hostile export cannot trigger XXE or SSRF.
 */
final class WxrParser
{
    private const WP_NS = 'http://wordpress.org/export/1.2/';

    /**
     * @return list<WxrPost>
     */
    public function parse(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks network access; entities are not expanded (no NOENT/
        // DTDLOAD), closing XXE.
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('wp', self::WP_NS);

        $items = $xpath->query('//channel/item');
        if ($items === false) {
            return [];
        }

        $posts = [];
        foreach ($items as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $posts[] = new WxrPost(
                title: $this->text($xpath, 'title', $item),
                slug: $this->text($xpath, 'wp:post_name', $item),
                link: $this->text($xpath, 'link', $item),
                status: $this->text($xpath, 'wp:status', $item),
                postmeta: $this->postmeta($xpath, $item),
            );
        }

        return $posts;
    }

    /**
     * @return array<string, string>
     */
    private function postmeta(DOMXPath $xpath, DOMElement $item): array
    {
        $meta = [];

        $nodes = $xpath->query('wp:postmeta', $item);
        if ($nodes === false) {
            return $meta;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $key = $this->text($xpath, 'wp:meta_key', $node);
            if ($key !== '') {
                $meta[$key] = $this->text($xpath, 'wp:meta_value', $node);
            }
        }

        return $meta;
    }

    private function text(DOMXPath $xpath, string $query, DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false) {
            return '';
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }
}
