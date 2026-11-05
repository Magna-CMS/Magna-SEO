<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Magna\Blocks\PageTree;
use Magna\Pages\Seo\PagesSeoSource;
use Magna\Seo\Analysis\AnalysisCache;
use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Analysis\BlockText;
use Magna\Seo\Analysis\RecordAnalyser;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use ReflectionMethod;

/**
 * A builder page has no body column, so its text has to come from the block
 * document. Saving one used to score it as an empty page, which put full pages
 * in the red on the SEO Content screen.
 */
final class BuilderPageScoresTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    /**
     * @return array<mixed>
     */
    private function document(string $body = ''): array
    {
        $body = $body !== '' ? $body : '<p>'.str_repeat('Billing software keeps every bill on your device first. ', 40).'</p>';

        return [
            'schemaVersion' => '1.0',
            'sections' => [[
                'id' => 's1',
                'type' => 'section',
                'settings' => [],
                'columns' => [[
                    'id' => 'c1',
                    'span' => 12,
                    'settings' => [],
                    'blocks' => [
                        [
                            'id' => 'b1',
                            'block' => 'hero',
                            'settings' => [],
                            'data' => [
                                'headline' => 'Billing software &amp; stock',
                                'cta_primary_url' => '/pricing',
                                'image' => 'https://site.test/hero.png',
                            ],
                        ],
                        ['id' => 'b2', 'block' => 'text', 'settings' => [], 'data' => ['body' => $body]],
                        [
                            'id' => 'b3',
                            'block' => 'container',
                            'settings' => [],
                            'data' => [],
                            'children' => [[
                                'id' => 'b4',
                                'block' => 'faq',
                                'settings' => [],
                                'data' => ['items' => [['question' => 'Does it work offline?', 'answer' => 'Yes.']]],
                            ]],
                        ],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $attributes): Model
    {
        $record = new class extends Model
        {
            protected $casts = ['blocks_data' => 'array'];
        };

        $record->setRawAttributes(['slug' => 'pricing']);

        foreach ($attributes as $key => $value) {
            $record->setAttribute($key, $value);
        }

        return $record;
    }

    private function resultFor(AnalysisReport $report, string $check): string
    {
        foreach ($report->results as $result) {
            if ($result->check === $check) {
                return $result->message;
            }
        }

        $this->fail("No result for [{$check}].");
    }

    public function test_block_text_reads_content_and_skips_links_and_media(): void
    {
        $text = BlockText::from($this->document('<p>Shop <strong>bills</strong></p>'));

        $this->assertStringContainsString('Billing software & stock', $text);
        $this->assertStringContainsString('Shop bills', $text);
        $this->assertStringContainsString('Does it work offline?', $text);
        $this->assertStringNotContainsString('/pricing', $text);
        $this->assertStringNotContainsString('hero.png', $text);
        $this->assertStringNotContainsString('<strong>', $text);
    }

    public function test_block_text_reads_the_legacy_list_shape_too(): void
    {
        $sections = $this->document('<p>Legacy page</p>')['sections'];

        $this->assertStringContainsString('Legacy page', BlockText::from($sections));
    }

    public function test_block_text_of_an_empty_document_is_empty(): void
    {
        $this->assertSame('', BlockText::from([]));
    }

    public function test_a_builder_page_is_scored_on_its_block_text(): void
    {
        $analyser = app(RecordAnalyser::class);
        $title = 'Billing software for shops';

        $empty = $analyser->analyse($this->record(['blocks_data' => []]), 'billing software', $title, null);
        $full = $analyser->analyse($this->record(['blocks_data' => $this->document()]), 'billing software', $title, null);

        $this->assertStringContainsString('(0 words)', $this->resultFor($empty, 'content-length'));
        $this->assertStringNotContainsString('(0 words)', $this->resultFor($full, 'content-length'));
        $this->assertStringContainsString('appears early', $this->resultFor($full, 'keyword-in-opening'));
        $this->assertGreaterThan($empty->seoScore, $full->seoScore);
    }

    public function test_a_body_field_still_wins_over_a_block_document(): void
    {
        $report = app(RecordAnalyser::class)->analyse(
            $this->record(['body' => '<p>Only three words</p>', 'blocks_data' => $this->document()]),
            '',
            null,
            null,
        );

        $this->assertStringContainsString('(3 words)', $this->resultFor($report, 'content-length'));
    }

    public function test_block_text_matches_what_the_pages_source_gives_the_scan(): void
    {
        if (! class_exists(PagesSeoSource::class)) {
            $this->markTestSkipped('Magna Pages is not installed.');
        }

        $document = $this->document();
        $read = new ReflectionMethod(PagesSeoSource::class, 'plainText');

        $this->assertSame(
            $read->invoke(app(PagesSeoSource::class), PageTree::fromArray($document)),
            BlockText::from($document),
        );
    }

    public function test_a_scan_scores_the_seo_title_that_gets_published(): void
    {
        $repository = app(SeoMetaRepository::class);
        $repository->upsert(User::class, '1', [
            'title' => 'Billing software pricing',
            'focus_keywords' => ['billing software'],
        ]);

        $report = app(AnalysisCache::class)->refresh(new SeoSubject(
            key: 'fake:1',
            type: SubjectType::Page,
            url: 'https://site.test/pricing',
            title: 'Pricing',
            locale: 'en',
            indexable: true,
            updatedAt: new \DateTimeImmutable,
            plainText: str_repeat('Billing software keeps every bill safe. ', 40),
            modelId: '1',
        ), User::class);

        $this->assertNotNull($report);
        $this->assertSame('The focus keyword appears in the title.', $this->resultFor($report, 'keyword-in-title'));
    }
}
