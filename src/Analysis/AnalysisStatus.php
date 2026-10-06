<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * The verdict of a single content-analysis check, and how much it contributes to
 * the overall score.
 */
enum AnalysisStatus: string
{
    case Good = 'good';
    case Ok = 'ok';
    case Bad = 'bad';

    public function weight(): float
    {
        return match ($this) {
            self::Good => 1.0,
            self::Ok => 0.5,
            self::Bad => 0.0,
        };
    }
}
