<?php

declare(strict_types=1);

namespace Magna\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Magna\Seo\Redirects\MatchType;
use Magna\Seo\Redirects\RedirectStore;

/**
 * One redirect rule. Rules are authored by editors and consumed by
 * {@see RedirectStore}, which is the only place matching logic lives.
 *
 * `source_hash` is maintained here rather than by callers so an exact-match rule
 * can never be saved with a stale lookup key.
 *
 * @property int $id
 * @property string $source_path
 * @property string $match_type
 * @property string|null $target
 * @property int $status_code
 * @property bool $preserve_query
 * @property bool $is_active
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property string|null $notes
 * @property string $source_hash
 */
final class SeoRedirect extends Model
{
    protected $table = 'seo_redirects';

    /** @var list<string> */
    protected $fillable = [
        'source_path',
        'match_type',
        'target',
        'status_code',
        'preserve_query',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preserve_query' => 'boolean',
            'is_active' => 'boolean',
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (SeoRedirect $redirect): void {
            if ($redirect->match_type === MatchType::Exact->value) {
                $redirect->source_path = RedirectStore::normaliseSource($redirect->source_path);
            }

            $redirect->source_hash = $redirect->match_type === MatchType::Exact->value
                ? hash('sha256', $redirect->source_path)
                : '';
        });
    }
}
