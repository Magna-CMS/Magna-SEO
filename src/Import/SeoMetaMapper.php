<?php

declare(strict_types=1);

namespace Magna\Seo\Import;

/**
 * Maps a WordPress post's meta from Yoast SEO or Rank Math into Magna's normalized
 * SEO override shape (the columns of magna_seo_meta). Pure and side-effect free,
 * so the mapping is fully testable without a WordPress database.
 *
 * The plugin is detected from the keys present. Only fields that carry a value are
 * returned, so an import never overwrites a Magna field with an empty string.
 */
final class SeoMetaMapper
{
    /**
     * @param  array<string, string>  $postmeta  flat meta_key => meta_value map
     * @return array<string, mixed>|null normalized override fields, or null when no supported plugin is recognised
     */
    public function map(array $postmeta): ?array
    {
        if ($this->hasPrefix($postmeta, '_yoast_wpseo_')) {
            return $this->fromYoast($postmeta);
        }

        if ($this->hasPrefix($postmeta, 'rank_math_')) {
            return $this->fromRankMath($postmeta);
        }

        if ($this->hasPrefix($postmeta, '_aioseo_')) {
            return $this->fromAioseo($postmeta);
        }

        if ($this->hasPrefix($postmeta, '_seopress_')) {
            return $this->fromSeoPress($postmeta);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function fromYoast(array $m): array
    {
        $out = [];
        $this->put($out, 'title', $m['_yoast_wpseo_title'] ?? null);
        $this->put($out, 'description', $m['_yoast_wpseo_metadesc'] ?? null);
        $this->put($out, 'canonical_url', $m['_yoast_wpseo_canonical'] ?? null);
        $this->put($out, 'og_title', $m['_yoast_wpseo_opengraph-title'] ?? null);
        $this->put($out, 'og_description', $m['_yoast_wpseo_opengraph-description'] ?? null);

        $keyword = trim($m['_yoast_wpseo_focuskw'] ?? '');
        if ($keyword !== '') {
            $out['focus_keywords'] = [$keyword];
        }

        if (($m['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1') {
            $out['robots_index'] = false;
        }
        if (($m['_yoast_wpseo_meta-robots-nofollow'] ?? '') === '1') {
            $out['robots_follow'] = false;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function fromRankMath(array $m): array
    {
        $out = [];
        $this->put($out, 'title', $m['rank_math_title'] ?? null);
        $this->put($out, 'description', $m['rank_math_description'] ?? null);
        $this->put($out, 'canonical_url', $m['rank_math_canonical_url'] ?? null);
        $this->put($out, 'og_title', $m['rank_math_facebook_title'] ?? null);
        $this->put($out, 'og_description', $m['rank_math_facebook_description'] ?? null);
        $this->put($out, 'twitter_card', $m['rank_math_twitter_card_type'] ?? null);

        $keyword = trim($m['rank_math_focus_keyword'] ?? '');
        if ($keyword !== '') {
            // Rank Math stores comma-separated keywords; the first is the focus.
            $keywords = array_values(array_filter(array_map('trim', explode(',', $keyword))));
            if ($keywords !== []) {
                $out['focus_keywords'] = $keywords;
            }
        }

        // rank_math_robots is a serialised/joined list; a substring test is enough
        // to read the two flags we model.
        $robots = $m['rank_math_robots'] ?? '';
        if (str_contains($robots, 'noindex')) {
            $out['robots_index'] = false;
        }
        if (str_contains($robots, 'nofollow')) {
            $out['robots_follow'] = false;
        }

        return $out;
    }

    /**
     * All in One SEO. Its postmeta keys are the legacy flat ones; newer versions
     * keep most data in a custom table that a WXR export does not carry, so what
     * is here is what an export can actually give us.
     *
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function fromAioseo(array $m): array
    {
        $out = [];
        $this->put($out, 'title', $m['_aioseo_title'] ?? null);
        $this->put($out, 'description', $m['_aioseo_description'] ?? null);
        $this->put($out, 'canonical_url', $m['_aioseo_canonical_url'] ?? null);
        $this->put($out, 'og_title', $m['_aioseo_og_title'] ?? null);
        $this->put($out, 'og_description', $m['_aioseo_og_description'] ?? null);
        $this->put($out, 'twitter_title', $m['_aioseo_twitter_title'] ?? null);
        $this->put($out, 'twitter_description', $m['_aioseo_twitter_description'] ?? null);

        $keyword = trim($m['_aioseo_keywords'] ?? '');
        if ($keyword !== '') {
            $keywords = array_values(array_filter(array_map('trim', explode(',', $keyword))));
            if ($keywords !== []) {
                $out['focus_keywords'] = $keywords;
            }
        }

        if (($m['_aioseo_robots_noindex'] ?? '') === '1') {
            $out['robots_index'] = false;
        }
        if (($m['_aioseo_robots_nofollow'] ?? '') === '1') {
            $out['robots_follow'] = false;
        }

        return $out;
    }

    /**
     * SEOPress.
     *
     * @param  array<string, string>  $m
     * @return array<string, mixed>
     */
    private function fromSeoPress(array $m): array
    {
        $out = [];
        $this->put($out, 'title', $m['_seopress_titles_title'] ?? null);
        $this->put($out, 'description', $m['_seopress_titles_desc'] ?? null);
        $this->put($out, 'canonical_url', $m['_seopress_robots_canonical'] ?? null);
        $this->put($out, 'og_title', $m['_seopress_social_fb_title'] ?? null);
        $this->put($out, 'og_description', $m['_seopress_social_fb_desc'] ?? null);
        $this->put($out, 'twitter_title', $m['_seopress_social_twitter_title'] ?? null);
        $this->put($out, 'twitter_description', $m['_seopress_social_twitter_desc'] ?? null);

        $keyword = trim($m['_seopress_analysis_target_kw'] ?? '');
        if ($keyword !== '') {
            $keywords = array_values(array_filter(array_map('trim', explode(',', $keyword))));
            if ($keywords !== []) {
                $out['focus_keywords'] = $keywords;
            }
        }

        // SEOPress stores each robots directive as its own "yes"/"" flag.
        if (($m['_seopress_robots_index'] ?? '') === 'yes') {
            $out['robots_index'] = false;
        }
        if (($m['_seopress_robots_follow'] ?? '') === 'yes') {
            $out['robots_follow'] = false;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function put(array &$out, string $key, ?string $value): void
    {
        $value = $value !== null ? trim($value) : '';
        if ($value !== '') {
            $out[$key] = $value;
        }
    }

    /**
     * @param  array<string, string>  $postmeta
     */
    private function hasPrefix(array $postmeta, string $prefix): bool
    {
        foreach (array_keys($postmeta) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
