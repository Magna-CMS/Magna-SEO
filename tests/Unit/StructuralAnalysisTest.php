<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\Checks\HeadingStructureCheck;
use Magna\Seo\Analysis\Checks\ImageAltCheck;
use Magna\Seo\Analysis\Checks\KeywordInHeadingCheck;
use Magna\Seo\Analysis\Checks\LinkCountCheck;
use Magna\Seo\Analysis\Checks\ParagraphLengthCheck;
use Magna\Seo\Analysis\Checks\PassiveVoiceCheck;
use Magna\Seo\Analysis\Checks\SentenceLengthCheck;
use Magna\Seo\Analysis\Checks\TransitionWordsCheck;
use Magna\Seo\Analysis\DocumentOutline;
use PHPUnit\Framework\TestCase;

/**
 * The structural half of the content analysis: everything that needs the body
 * markup rather than the stripped text.
 */
final class StructuralAnalysisTest extends TestCase
{
    private function input(string $html, string $keyword = '', string $text = '', string $siteUrl = 'https://site.test/p'): AnalysisInput
    {
        return new AnalysisInput(
            keyword: $keyword,
            title: 'Title',
            description: 'Description',
            slug: 'p',
            plainText: $text !== '' ? $text : trim(strip_tags($html)),
            html: $html,
            siteUrl: $siteUrl,
        );
    }

    public function test_the_outline_separates_internal_from_outbound_links(): void
    {
        $outline = new DocumentOutline(
            '<a href="/about">a</a><a href="https://site.test/x">b</a><a href="https://other.test/y">c</a>'
            .'<a href="#anchor">d</a><a href="mailto:x@y.z">e</a>',
            'https://site.test/p',
        );

        $this->assertSame(2, $outline->internalLinkCount());
        $this->assertSame(1, $outline->outboundLinkCount());
    }

    public function test_the_outline_ignores_decorative_images(): void
    {
        $outline = new DocumentOutline('<img src="a.jpg" alt="A"><img src="b.jpg" alt=""><img src="c.jpg">', '');

        $this->assertCount(3, $outline->images);
        // The empty-alt image is decorative by declaration; the one with no alt
        // attribute at all is an omission.
        $this->assertCount(2, $outline->meaningfulImages());
    }

    public function test_keyword_in_heading_prefers_the_h1(): void
    {
        $check = new KeywordInHeadingCheck;

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input('<h1>Best coffee beans</h1>', 'coffee beans'))->status);
        $this->assertSame(AnalysisStatus::Ok, $check->analyse($this->input('<h1>Beans</h1><h2>Best coffee beans</h2>', 'coffee beans'))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input('<h1>Something else</h1>', 'coffee beans'))->status);
        $this->assertSame(AnalysisStatus::Ok, $check->analyse($this->input('<h1>Anything</h1>'))->status);
    }

    public function test_heading_structure_flags_multiple_h1s_and_skipped_levels(): void
    {
        $check = new HeadingStructureCheck;

        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input('<h1>A</h1><h1>B</h1>'))->status);
        $this->assertSame(AnalysisStatus::Ok, $check->analyse($this->input('<h1>A</h1><h4>B</h4>'))->status);
        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input('<h1>A</h1><h2>B</h2><h3>C</h3>'))->status);
    }

    public function test_heading_structure_expects_subheadings_on_a_long_page(): void
    {
        $long = str_repeat('word ', 400);

        $result = (new HeadingStructureCheck)->analyse($this->input('<h1>A</h1><p>'.$long.'</p>'));

        $this->assertSame(AnalysisStatus::Bad, $result->status);
        $this->assertStringContainsString('no subheadings', $result->message);
    }

    public function test_a_page_with_no_internal_links_is_a_dead_end(): void
    {
        $check = new LinkCountCheck;

        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input('<p>No links</p>'))->status);
        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input('<a href="/other">x</a><a href="https://ref.test">y</a>'))->status);
    }

    public function test_missing_alt_text_is_reported_with_counts(): void
    {
        $result = (new ImageAltCheck)->analyse($this->input('<img src="a.jpg" alt="A"><img src="b.jpg">'));

        $this->assertSame(AnalysisStatus::Ok, $result->status);
        $this->assertStringContainsString('1 of 2', $result->message);

        $this->assertSame(
            AnalysisStatus::Good,
            (new ImageAltCheck)->analyse($this->input('<img src="a.jpg" alt="A">'))->status,
        );
    }

    public function test_paragraph_and_sentence_length_flag_walls_of_text(): void
    {
        $wall = '<p>'.str_repeat('word ', 200).'</p>';

        $this->assertSame(AnalysisStatus::Ok, (new ParagraphLengthCheck)->analyse($this->input($wall))->status);
        $this->assertSame(
            AnalysisStatus::Bad,
            (new ParagraphLengthCheck)->analyse($this->input($wall.$wall.$wall))->status,
        );

        $longSentence = str_repeat('word ', 40).'.';
        $this->assertSame(
            AnalysisStatus::Bad,
            (new SentenceLengthCheck)->analyse($this->input('<p>x</p>', text: $longSentence))->status,
        );
        $this->assertSame(
            AnalysisStatus::Good,
            (new SentenceLengthCheck)->analyse($this->input('<p>x</p>', text: 'Short one. Short two. Short three.'))->status,
        );
    }

    public function test_passive_voice_is_detected_including_irregular_participles(): void
    {
        $check = new PassiveVoiceCheck;

        $passive = 'The report was written by the team. The rules were changed. The prize was given away. It was quickly replaced.';
        $active = 'The team wrote the report. Someone changed the rules. We gave the prize away. They replaced it.';

        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input('', text: $passive))->status);
        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input('', text: $active))->status);
    }

    public function test_transition_words_are_counted_per_sentence(): void
    {
        $check = new TransitionWordsCheck;

        $connected = 'However, the plan worked. Therefore we shipped it. Moreover, users liked it.';
        $disjointed = 'The plan worked. We shipped it. Users liked it. Nothing broke.';

        $this->assertSame(AnalysisStatus::Good, $check->analyse($this->input('', text: $connected))->status);
        $this->assertSame(AnalysisStatus::Bad, $check->analyse($this->input('', text: $disjointed))->status);
    }

    public function test_structural_checks_stay_neutral_without_markup(): void
    {
        $input = new AnalysisInput('kw', 'T', 'D', 's', 'Some plain text.');

        foreach ([new HeadingStructureCheck, new LinkCountCheck, new ImageAltCheck, new KeywordInHeadingCheck] as $check) {
            $result = $check->analyse($input);

            $this->assertSame(AnalysisStatus::Ok, $result->status, $result->check);
        }
    }
}
