<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Llms\LlmsTxtGenerator;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

/**
 * /llms.txt and /llms-full.txt: the curated markdown map for language models.
 */
final class LlmsTxtTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        Cache::flush();
    }

    private function subject(string $id, string $title, bool $indexable = true, string $text = ''): SeoSubject
    {
        return new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://site.test/'.$id,
            title: $title,
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
            plainText: $text,
            excerpt: $text !== '' ? null : 'Short description.',
            modelId: $id,
        );
    }

    /**
     * @param  array<string, SeoSubject>  $subjects
     */
    private function register(array $subjects, ?string $modelClass = null, string $handle = 'guides'): LlmsTxtGenerator
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $registry->register(new FakeSeoSubjectSource($handle, $subjects, modelClass: $modelClass));

        return new LlmsTxtGenerator($registry, new SeoMetaRepository);
    }

    public function test_it_writes_a_titled_map_grouped_by_source(): void
    {
        $settings = SeoSettings::get();
        $settings->site_name = 'Acme Docs';
        $settings->llms_txt_summary = 'Everything about the Acme platform.';
        $settings->save();

        $document = $this->register([
            'intro' => $this->subject('intro', 'Introduction'),
            'setup' => $this->subject('setup', 'Setup'),
        ])->index();

        $this->assertStringContainsString('# Acme Docs', $document);
        $this->assertStringContainsString('> Everything about the Acme platform.', $document);
        $this->assertStringContainsString('## Fake', $document);
        $this->assertStringContainsString('- [Introduction](https://site.test/intro): Short description.', $document);
        $this->assertStringContainsString('- [Setup](https://site.test/setup)', $document);
    }

    public function test_unpublished_pages_never_appear(): void
    {
        $document = $this->register([
            'live' => $this->subject('live', 'Live page'),
            'draft' => $this->subject('draft', 'Draft page', indexable: false),
        ])->index();

        $this->assertStringContainsString('Live page', $document);
        $this->assertStringNotContainsString('Draft page', $document);
    }

    public function test_a_page_an_editor_marked_noindex_is_excluded(): void
    {
        $generator = $this->register([
            'keep' => $this->subject('keep', 'Keep me'),
            'hide' => $this->subject('hide', 'Hide me'),
        ], modelClass: DocPage::class);

        $this->app->make(SeoMetaRepository::class)->upsert(DocPage::class, 'hide', ['robots_index' => false]);

        $document = $generator->index();

        $this->assertStringContainsString('Keep me', $document);
        $this->assertStringNotContainsString('Hide me', $document);
    }

    public function test_markdown_syntax_in_a_title_cannot_break_the_link(): void
    {
        $document = $this->register([
            'x' => $this->subject('x', 'Buy [now](http://evil.test) cheap'),
        ])->index();

        $this->assertStringContainsString('\[now\]\(http://evil.test\)', $document);
        $this->assertStringNotContainsString('](http://evil.test)', $document);
    }

    public function test_the_full_variant_inlines_page_text_and_respects_its_budget(): void
    {
        config()->set('seo.llms.full_max_characters', 400);

        $generator = $this->register([
            'a' => $this->subject('a', 'First', text: str_repeat('alpha ', 40)),
            'b' => $this->subject('b', 'Second', text: str_repeat('beta ', 200)),
        ]);

        $document = $generator->full();

        $this->assertStringContainsString('Source: https://site.test/a', $document);
        $this->assertStringContainsString('alpha', $document);
        $this->assertStringContainsString('Truncated:', $document);
    }

    public function test_the_endpoints_serve_markdown_and_respect_their_settings(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        // The full variant is opt-in.
        $this->get('/llms-full.txt')->assertNotFound();

        $settings = SeoSettings::get();
        $settings->llms_full_enabled = true;
        $settings->save();

        $this->get('/llms-full.txt')->assertOk();

        $settings->llms_txt_enabled = false;
        $settings->save();

        $this->get('/llms.txt')->assertNotFound();
        $this->get('/llms-full.txt')->assertNotFound();
    }

    public function test_purging_lets_new_content_appear(): void
    {
        $source = new FakeSeoSubjectSource('mutable', ['a' => $this->subject('a', 'First')]);
        $registry = $this->app->make(SeoSourceRegistry::class);
        $registry->register($source);
        $generator = new LlmsTxtGenerator($registry, new SeoMetaRepository);

        $this->assertStringNotContainsString('Second', $generator->index());

        $source->replace(['a' => $this->subject('a', 'First'), 'b' => $this->subject('b', 'Second')]);
        $this->assertStringNotContainsString('Second', $generator->index(), 'still cached');

        $generator->purge();
        $this->assertStringContainsString('Second', $generator->index());
    }
}
