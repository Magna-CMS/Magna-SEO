<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Head\HeadHtmlRenderer;
use Magna\Seo\Head\HeadPayload;
use PHPUnit\Framework\TestCase;

final class HeadHtmlRendererTest extends TestCase
{
    public function test_it_renders_title_metas_properties_and_links(): void
    {
        $html = (new HeadHtmlRenderer)->render(new HeadPayload(
            title: 'Home',
            metaNames: ['description' => 'A site', 'robots' => 'index, follow'],
            metaProperties: ['og:title' => 'Home'],
            links: ['canonical' => 'https://example.test/'],
        ));

        $this->assertStringContainsString('<title>Home</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="A site">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Home">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/">', $html);
    }

    public function test_it_renders_hreflang_alternates(): void
    {
        $html = (new HeadHtmlRenderer)->render(new HeadPayload(
            title: 'T',
            alternates: [
                ['hreflang' => 'en', 'href' => 'https://example.test/1'],
                ['hreflang' => 'fr', 'href' => 'https://example.test/fr/1'],
            ],
        ));

        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.test/1">', $html);
        $this->assertStringContainsString('hreflang="fr"', $html);
    }

    public function test_it_escapes_markup_in_title_and_attribute_values(): void
    {
        $html = (new HeadHtmlRenderer)->render(new HeadPayload(
            title: '"><script>alert(1)</script>',
            metaNames: ['description' => 'x" onload="alert(1)'],
        ));

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot; onload=&quot;', $html);
    }

    public function test_json_ld_cannot_break_out_of_the_script_element(): void
    {
        $html = (new HeadHtmlRenderer)->render(new HeadPayload(
            title: 'T',
            jsonLd: [['@type' => 'Thing', 'name' => '</script><img src=x onerror=alert(1)>']],
        ));

        // Exactly one opening tag, and the payload's own </script> is hex-encoded.
        $this->assertSame(1, substr_count($html, '<script type="application/ld+json">'));
        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertStringContainsString('<', $html);
    }
}
