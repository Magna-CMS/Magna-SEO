<?php

declare(strict_types=1);

namespace Magna\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A distinct 404 path with a hit counter, not an append-only request log: a
 * crawler probing thousands of nonsense URLs should cost one row each, and the
 * table is capped besides (see NotFoundLogger).
 *
 * @property int $id
 * @property string $path
 * @property string $path_hash
 * @property string|null $last_referrer
 * @property int $hits
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
final class SeoNotFound extends Model
{
    protected $table = 'seo_not_found_log';

    /** @var list<string> */
    protected $fillable = [
        'path',
        'path_hash',
        'last_referrer',
        'hits',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
