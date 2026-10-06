<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\Checks\ContentLengthCheck;
use Magna\Seo\Analysis\Checks\DescriptionLengthCheck;
use Magna\Seo\Analysis\Checks\KeywordDensityCheck;
use Magna\Seo\Analysis\Checks\KeywordInDescriptionCheck;
use Magna\Seo\Analysis\Checks\KeywordInFirstParagraphCheck;
use Magna\Seo\Analysis\Checks\KeywordInSlugCheck;
use Magna\Seo\Analysis\Checks\KeywordInTitleCheck;
use Magna\Seo\Analysis\Checks\ReadingEaseCheck;
use Magna\Seo\Analysis\Checks\TitleLengthCheck;
use Magna\Seo\Analysis\ContentAnalyser;
use Magna\Seo\Analysis\ReadingEase;
use PHPUnit\Framework\TestCase;

final class ContentAnalyserTest extends TestCase
{
    private function analyser(): ContentAnalyser
    {
        return new ContentAnalyser([
            new KeywordInTitleCheck,
            new KeywordInDescriptionCheck,
            new KeywordInSlugCheck,
            new KeywordInFirstParagraphCheck,
            new KeywordDensityCheck,
            new ContentLengthCheck,
            new TitleLengthCheck,
            new DescriptionLengthCheck,
            new ReadingEaseCheck(new ReadingEase),
        ]);
    }

    public function test_a_well_optimised_page_scores_high(): void
    {
        $body = 'Coffee brewing rewards patience. Coffee brewing is a simple craft. '
            .str_repeat('A good cup starts with fresh beans and clean water. ', 40);

        $report = $this->analyser()->analyse(new AnalysisInput(
            keyword: 'coffee brewing',
            title: 'The Complete Coffee Brewing Guide for Home',
            description: 'Learn coffee brewing at home with simple, repeatable steps for a better cup every single morning, from fresh beans to your favourite mug.',
            slug: 'coffee-brewing-guide',
            plainText: $body,
        ));

        $this->assertGreaterThanOrEqual(60, $report->score);
        $this->assertLessThanOrEqual(100, $report->score);
        $this->assertNotEmpty($report->results);
    }

    public function test_a_poor_page_scores_low(): void
    {
        $report = $this->analyser()->analyse(new AnalysisInput(
            keyword: 'photosynthesis',
            title: 'Hi',
            description: '',
            slug: 'page',
            plainText: 'Short note.',
        ));

        $this->assertLessThanOrEqual(40, $report->score);
    }

    public function test_the_report_serialises_for_the_api(): void
    {
        $array = $this->analyser()->analyse(new AnalysisInput('seo', 'Title', 'Desc', 'slug', 'body'))->toArray();

        $this->assertArrayHasKey('score', $array);
        $this->assertArrayHasKey('results', $array);
        $this->assertArrayHasKey('check', $array['results'][0]);
        $this->assertArrayHasKey('status', $array['results'][0]);
    }
}
