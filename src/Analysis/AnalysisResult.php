<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

/**
 * The outcome of one content-analysis check: which check, its verdict, and a
 * human-readable, actionable explanation (never a bare score).
 */
final readonly class AnalysisResult
{
    public function __construct(
        public string $check,
        public AnalysisStatus $status,
        public string $message,
    ) {}
}
