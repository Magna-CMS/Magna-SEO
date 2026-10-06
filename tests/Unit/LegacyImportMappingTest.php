<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Import\SeoMetaMapper;
use PHPUnit\Framework\TestCase;

/**
 * The two importers added to close S10: All in One SEO and SEOPress. Both store
 * their robots directives differently from Yoast and Rank Math, which is where a
 * mapping bug would silently de-index an imported site.
 */
final class LegacyImportMappingTest extends TestCase
{
    private function mapper(): SeoMetaMapper
    {
        return new SeoMetaMapper;
    }

    public function test_it_maps_all_in_one_seo(): void
    {
        $mapped = $this->mapper()->map([
            '_aioseo_title' => 'AIOSEO title',
            '_aioseo_description' => 'AIOSEO description',
            '_aioseo_canonical_url' => 'https://example.com/canonical',
            '_aioseo_og_title' => 'Social title',
            '_aioseo_twitter_description' => 'Tweet description',
            '_aioseo_keywords' => 'coffee, beans',
            '_aioseo_robots_noindex' => '1',
        ]);

        $this->assertSame('AIOSEO title', $mapped['title']);
        $this->assertSame('AIOSEO description', $mapped['description']);
        $this->assertSame('https://example.com/canonical', $mapped['canonical_url']);
        $this->assertSame('Social title', $mapped['og_title']);
        $this->assertSame('Tweet description', $mapped['twitter_description']);
        $this->assertSame(['coffee', 'beans'], $mapped['focus_keywords']);
        $this->assertFalse($mapped['robots_index']);
        $this->assertArrayNotHasKey('robots_follow', $mapped);
    }

    public function test_it_maps_seopress_including_its_inverted_robots_flags(): void
    {
        $mapped = $this->mapper()->map([
            '_seopress_titles_title' => 'SEOPress title',
            '_seopress_titles_desc' => 'SEOPress description',
            '_seopress_social_fb_title' => 'FB title',
            '_seopress_analysis_target_kw' => 'espresso',
            // In SEOPress these flags mean "do not index" / "do not follow".
            '_seopress_robots_index' => 'yes',
            '_seopress_robots_follow' => 'yes',
        ]);

        $this->assertSame('SEOPress title', $mapped['title']);
        $this->assertSame('FB title', $mapped['og_title']);
        $this->assertSame(['espresso'], $mapped['focus_keywords']);
        $this->assertFalse($mapped['robots_index']);
        $this->assertFalse($mapped['robots_follow']);
    }

    public function test_empty_values_are_never_imported_over_existing_ones(): void
    {
        $mapped = $this->mapper()->map([
            '_aioseo_title' => '   ',
            '_aioseo_description' => 'Only this one is set.',
        ]);

        $this->assertArrayNotHasKey('title', $mapped);
        $this->assertSame('Only this one is set.', $mapped['description']);
    }

    public function test_meta_from_no_recognised_plugin_maps_to_nothing(): void
    {
        $this->assertNull($this->mapper()->map(['_thumbnail_id' => '42']));
    }
}
