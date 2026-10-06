<?php

declare(strict_types=1);

namespace Magna\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of the site scan, with a rollup of how many URLs were checked and how
 * many issues were found.
 *
 * @property int $id
 * @property int $url_count
 * @property int $issue_count
 * @property int|null $score
 * @property array<string, int>|null $check_stats
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SeoScan extends Model
{
    protected $table = 'seo_scans';

    /** @var list<string> */
    protected $fillable = ['url_count', 'issue_count', 'score', 'check_stats'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['check_stats' => 'array'];
    }

    /**
     * How many subjects passed a given check, as a percentage.
     *
     * Only meaningful for checks that run once per page. Site-level checks
     * (robots.txt, redirect rules) have no denominator and return null rather
     * than a misleading 100%.
     */
    public function passRateFor(string $check): ?int
    {
        if ($this->url_count < 1) {
            return null;
        }

        $failed = $this->check_stats[$check] ?? null;

        if ($failed === null) {
            return null;
        }

        return (int) round((1 - min($failed, $this->url_count) / $this->url_count) * 100);
    }

    /**
     * @return HasMany<SeoScanIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SeoScanIssue::class);
    }
}
