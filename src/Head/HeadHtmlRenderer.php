<?php

declare(strict_types=1);

namespace Magna\Seo\Head;

/**
 * Serialises a {@see HeadPayload} to an HTML `<head>` fragment with
 * context-correct escaping applied exactly once here.
 *
 * Text and attribute values go through htmlspecialchars with ENT_QUOTES so a
 * value containing `"` or `<` cannot break out of an attribute or the title.
 * JSON-LD is encoded with the hardened flag set below so that a `</script>` or a
 * `<` inside any string cannot terminate the script element or inject markup —
 * this is the one place manual string concatenation of JSON would be a stored-XSS
 * hole, so it is never done.
 */
final class HeadHtmlRenderer
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function render(HeadPayload $payload): string
    {
        $lines = [];

        $lines[] = '<title>'.$this->escape($payload->title).'</title>';

        foreach ($payload->metaNames as $name => $content) {
            $lines[] = '<meta name="'.$this->escape($name).'" content="'.$this->escape($content).'">';
        }

        foreach ($payload->metaProperties as $property => $content) {
            $lines[] = '<meta property="'.$this->escape($property).'" content="'.$this->escape($content).'">';
        }

        foreach ($payload->links as $rel => $href) {
            $lines[] = '<link rel="'.$this->escape($rel).'" href="'.$this->escape($href).'">';
        }

        foreach ($payload->alternates as $alternate) {
            $lines[] = '<link rel="alternate" hreflang="'.$this->escape($alternate['hreflang'])
                .'" href="'.$this->escape($alternate['href']).'">';
        }

        foreach ($payload->jsonLd as $node) {
            $json = json_encode($node, self::JSON_FLAGS);
            if ($json !== false) {
                $lines[] = '<script type="application/ld+json">'.$json.'</script>';
            }
        }

        return implode("\n", $lines);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
