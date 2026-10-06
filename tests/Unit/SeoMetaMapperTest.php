<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Import\SeoMetaMapper;
use PHPUnit\Framework\TestCase;

final class SeoMetaMapperTest extends TestCase
{
    public function test_it_maps_yoast_meta(): void
    {
        $mapped = (new SeoMetaMapper)->map([
            '_yoast_wpseo_title' => 'Yoast Title',
            '_yoast_wpseo_metadesc' => 'Yoast description.',
            '_yoast_wpseo_canonical' => 'https://old.test/post',
            '_yoast_wpseo_focuskw' => 'coffee',
            '_yoast_wpseo_meta-robots-noindex' => '1',
        ]);

        $this->assertSame('Yoast Title', $mapped['title']);
        $this->assertSame('Yoast description.', $mapped['description']);
        $this->assertSame('https://old.test/post', $mapped['canonical_url']);
        $this->assertSame(['coffee'], $mapped['focus_keywords']);
        $this->assertFalse($mapped['robots_index']);
        $this->assertArrayNotHasKey('robots_follow', $mapped);
    }

    public function test_it_maps_rank_math_meta(): void
    {
        $mapped = (new SeoMetaMapper)->map([
            'rank_math_title' => 'RM Title',
            'rank_math_description' => 'RM description.',
            'rank_math_focus_keyword' => 'tea, green tea',
            'rank_math_twitter_card_type' => 'summary_large_image',
            'rank_math_robots' => 'a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}',
        ]);

        $this->assertSame('RM Title', $mapped['title']);
        $this->assertSame(['tea', 'green tea'], $mapped['focus_keywords']);
        $this->assertSame('summary_large_image', $mapped['twitter_card']);
        $this->assertFalse($mapped['robots_index']);
        $this->assertFalse($mapped['robots_follow']);
    }

    public function test_empty_values_are_omitted(): void
    {
        $mapped = (new SeoMetaMapper)->map([
            '_yoast_wpseo_title' => '  ',
            '_yoast_wpseo_metadesc' => 'Only this.',
        ]);

        $this->assertArrayNotHasKey('title', $mapped);
        $this->assertSame('Only this.', $mapped['description']);
    }

    public function test_unrecognised_meta_returns_null(): void
    {
        $this->assertNull((new SeoMetaMapper)->map(['_some_other_plugin_title' => 'x']));
    }
}
