<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Import\SeoMetaMapper;
use Magna\Seo\Import\WxrParser;
use PHPUnit\Framework\TestCase;

final class WxrParserTest extends TestCase
{
    private function wxr(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
          <channel>
            <item>
              <title>Hello World</title>
              <link>https://old.test/hello-world</link>
              <wp:post_name>hello-world</wp:post_name>
              <wp:status>publish</wp:status>
              <wp:postmeta>
                <wp:meta_key>_yoast_wpseo_title</wp:meta_key>
                <wp:meta_value>Imported SEO Title</wp:meta_value>
              </wp:postmeta>
              <wp:postmeta>
                <wp:meta_key>_yoast_wpseo_metadesc</wp:meta_key>
                <wp:meta_value>Imported description.</wp:meta_value>
              </wp:postmeta>
            </item>
            <item>
              <title>Second</title>
              <link>https://old.test/second</link>
              <wp:post_name>second</wp:post_name>
              <wp:status>draft</wp:status>
            </item>
          </channel>
        </rss>
        XML;
    }

    public function test_it_parses_items_and_their_postmeta(): void
    {
        $posts = (new WxrParser)->parse($this->wxr());

        $this->assertCount(2, $posts);
        $this->assertSame('Hello World', $posts[0]->title);
        $this->assertSame('hello-world', $posts[0]->slug);
        $this->assertSame('publish', $posts[0]->status);
        $this->assertSame('Imported SEO Title', $posts[0]->postmeta['_yoast_wpseo_title']);
        $this->assertSame('draft', $posts[1]->status);
        $this->assertSame([], $posts[1]->postmeta);
    }

    public function test_parsed_meta_feeds_the_mapper(): void
    {
        $posts = (new WxrParser)->parse($this->wxr());

        $mapped = (new SeoMetaMapper)->map($posts[0]->postmeta);

        $this->assertSame('Imported SEO Title', $mapped['title']);
        $this->assertSame('Imported description.', $mapped['description']);
    }

    public function test_malformed_xml_returns_no_posts(): void
    {
        $this->assertSame([], (new WxrParser)->parse('<rss><channel><item>'));
    }

    public function test_external_entities_are_not_expanded(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'wxr');
        $this->assertNotFalse($secret);
        file_put_contents($secret, 'TOPSECRET');
        $uri = 'file:///'.ltrim(str_replace('\\', '/', $secret), '/');

        $xml = '<?xml version="1.0"?>'
            .'<!DOCTYPE rss [<!ENTITY xxe SYSTEM "'.$uri.'">]>'
            .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><item>'
            .'<title>&xxe;</title><wp:post_name>x</wp:post_name><wp:status>publish</wp:status>'
            .'</item></channel></rss>';

        $posts = (new WxrParser)->parse($xml);

        // The external entity must not be resolved into the document.
        $title = $posts[0]->title ?? '';
        $this->assertStringNotContainsString('TOPSECRET', $title);

        unlink($secret);
    }
}
