<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Illuminate\Support\Str;
use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

final class KeywordInSlugCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->hasKeyword()) {
            return new AnalysisResult('keyword-in-slug', AnalysisStatus::Ok, 'Set a focus keyword to check the URL.');
        }

        if ($input->slug === '') {
            return new AnalysisResult('keyword-in-slug', AnalysisStatus::Ok, 'This page has no slug to check.');
        }

        $found = str_contains($input->slug, Str::slug($input->keyword));

        return new AnalysisResult(
            'keyword-in-slug',
            $found ? AnalysisStatus::Good : AnalysisStatus::Bad,
            $found ? 'The focus keyword appears in the URL.' : 'Consider including the focus keyword in the URL slug.',
        );
    }
}
