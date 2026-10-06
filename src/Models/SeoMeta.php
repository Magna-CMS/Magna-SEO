<?php

declare(strict_types=1);

namespace Magna\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Magna\Seo\Support\SeoMetaRepository;

/**
 * Per-subject SEO overrides, attached polymorphically to any content model.
 *
 * The morph keys (seoable_type / seoable_id) are intentionally excluded from
 * $fillable and set explicitly by {@see SeoMetaRepository},
 * so untrusted input can never re-point a meta row at another entity.
 *
 * @property int $id
 * @property string $seoable_type
 * @property string $seoable_id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical_url
 * @property bool $robots_index
 * @property bool $robots_follow
 * @property array<string, mixed>|null $robots_advanced
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image_id
 * @property string|null $twitter_card
 * @property string|null $twitter_title
 * @property string|null $twitter_description
 * @property string|null $twitter_image_id
 * @property list<string>|null $focus_keywords
 * @property array<string, mixed>|null $schema_overrides
 * @property bool $is_cornerstone
 * @property array<string, mixed>|null $analysis_cache
 * @property Carbon|null $analysis_computed_at
 */
final class SeoMeta extends Model
{
    protected $table = 'magna_seo_meta';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'description',
        'canonical_url',
        'robots_index',
        'robots_follow',
        'robots_advanced',
        'og_title',
        'og_description',
        'og_image_id',
        'twitter_card',
        'twitter_title',
        'twitter_description',
        'twitter_image_id',
        'focus_keywords',
        'schema_overrides',
        'analysis_cache',
        'analysis_computed_at',
        'is_cornerstone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'is_cornerstone' => 'boolean',
            'robots_follow' => 'boolean',
            'robots_advanced' => 'array',
            'focus_keywords' => 'array',
            'schema_overrides' => 'array',
            'analysis_cache' => 'array',
            'analysis_computed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
