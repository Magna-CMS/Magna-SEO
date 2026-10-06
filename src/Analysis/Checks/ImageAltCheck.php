<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Alt-text coverage. Images explicitly marked decorative are excluded: they are
 * meant to have no alt text, and flagging them would push authors into writing
 * noise that screen readers then have to read out.
 */
final class ImageAltCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $outline = $input->outline;

        if (! $outline->hasMarkup) {
            return new AnalysisResult('image-alt', AnalysisStatus::Ok, 'No markup available to check image alt text.');
        }

        $images = $outline->meaningfulImages();

        if ($images === []) {
            return new AnalysisResult('image-alt', AnalysisStatus::Ok, 'No images to describe.');
        }

        $missing = count(array_filter($images, static fn (array $image): bool => $image['alt'] === ''));

        if ($missing === 0) {
            return new AnalysisResult('image-alt', AnalysisStatus::Good, 'Every image has alt text.');
        }

        $total = count($images);
        $status = $missing === $total ? AnalysisStatus::Bad : AnalysisStatus::Ok;

        return new AnalysisResult('image-alt', $status, "{$missing} of {$total} images have no alt text.");
    }
}
