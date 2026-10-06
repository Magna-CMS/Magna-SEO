<?php

declare(strict_types=1);

namespace Magna\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Magna\Seo\Scan\Severity;

/**
 * A single finding within a scan.
 *
 * @property int $id
 * @property int $seo_scan_id
 * @property string $source
 * @property string $subject_key
 * @property string|null $url
 * @property string $check
 * @property Severity $severity
 * @property string $message
 */
final class SeoScanIssue extends Model
{
    protected $table = 'seo_scan_issues';

    /** @var list<string> */
    protected $fillable = [
        'seo_scan_id',
        'source',
        'subject_key',
        'url',
        'check',
        'severity',
        'message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['severity' => Severity::class];
    }

    /**
     * @return BelongsTo<SeoScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(SeoScan::class, 'seo_scan_id');
    }
}
