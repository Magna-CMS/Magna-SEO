<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\Checks\ContentLengthCheck;
use Magna\Seo\Analysis\Checks\DescriptionLengthCheck;
use Magna\Seo\Analysis\Checks\KeywordDensityCheck;
use Magna\Seo\Analysis\Checks\KeywordInSlugCheck;
use Magna\Seo\Analysis\Checks\KeywordInTitleCheck;
use Magna\Seo\Analysis\Checks\TitleLengthCheck;
use PHPUnit\Framework\TestCase;

final class AnalysisChecksTest extends TestCase
{
    private function input(
        string $keyword = 'seo',
        string $title = 'Title',
        string $description = 'Description',
        string $slug = 'slug',
        string $plainText = 'body',
    ): AnalysisInput {
        return new AnalysisInput($keyword, $title, $description, $slug, $plainText);
    }

    public function test_keyword_in_title(): void
    {
        $check = new KeywordInTitleCheck;

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input(title: 'Best SEO tips'))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(title: 'Best tips'))->status);
        $this->assertSame(AnalysisStatus::Ok, $check->analyse($this->input(keyword: ''))->status);
    }

    public function test_keyword_in_slug_matches_the_slugified_keyword(): void
    {
        $check = new KeywordInSlugCheck;

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input(keyword: 'seo guide', slug: 'the-seo-guide-2026'))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(keyword: 'seo guide', slug: 'unrelated'))->status);
    }

    public function test_keyword_density_bands(): void
    {
        $check = new KeywordDensityCheck;
        $words = 'seo '.str_repeat('word ', 99); // 1 occurrence in 100 words = 1%

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input(plainText: $words))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(plainText: str_repeat('word ', 100)))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(plainText: str_repeat('seo ', 20)))->status);
    }

    public function test_content_length_bands(): void
    {
        $check = new ContentLengthCheck;

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input(plainText: str_repeat('word ', 300)))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(plainText: 'only three words'))->status);
    }

    public function test_title_length_bands(): void
    {
        $check = new TitleLengthCheck;

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input(title: str_repeat('a', 40)))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input(title: 'Hi'))->status);
    }

    public function test_missing_description_is_bad(): void
    {
        $this->assertSame(AnalysisStatus::Bad, (new DescriptionLengthCheck)->analyse($this->input(description: ''))->status);
        $this->assertSame(AnalysisStatus::Good, (new DescriptionLengthCheck)->analyse($this->input(description: str_repeat('a', 140)))->status);
    }
}
