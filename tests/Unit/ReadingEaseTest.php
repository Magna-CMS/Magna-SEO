<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Analysis\ReadingEase;
use PHPUnit\Framework\TestCase;

final class ReadingEaseTest extends TestCase
{
    public function test_simpler_text_scores_higher_than_complex_text(): void
    {
        $reading = new ReadingEase;

        $simple = 'The cat sat on the mat. The dog ran to the park. We had fun all day.';
        $complex = 'Notwithstanding the aforementioned considerations, the institutional '
            .'ramifications necessitate comprehensive interdisciplinary deliberation.';

        $this->assertGreaterThan($reading->score($complex), $reading->score($simple));
    }

    public function test_the_score_is_clamped_to_the_valid_range(): void
    {
        $reading = new ReadingEase;

        $score = $reading->score('The cat sat. The dog ran. We had fun.');

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }
}
