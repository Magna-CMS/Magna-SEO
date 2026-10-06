<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Analysis\LinkSuggester;
use Magna\Seo\Analysis\ProminentWords;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Meta\TypeDefaults;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Seo\Tests\Support\FakeSeoSubjectSource;
use Magna\Testing\PluginTestCase;

/**
 * Phase 4: per-content-type defaults, cornerstone content, prominent words and
 * internal-link suggestions.
 */
final class EditorialDepthTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function subject(string $id, string $title, string $text = '', array $raw = []): SeoSubject
    {
        return new SeoSubject(
            key: 'fake:'.$id,
            type: SubjectType::Page,
            url: 'https://site.test/'.$id,
            title: $title,
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
            plainText: $text,
            raw: $raw,
            modelId: $id,
        );
    }

    private function settingsWithTypeDefaults(): SeoSettings
    {
        $settings = new SeoSettings;
        $settings->site_name = 'Acme';
        $settings->default_title_template = '%%title%% %%sep%% %%sitename%%';
        $settings->type_defaults = [
            'doc' => [
                'title_template' => '%%title%% — Acme Docs',
                'description_template' => 'Documentation: %%excerpt%%',
            ],
            'widget' => ['robots_index' => false],
        ];

        return $settings;
    }

    public function test_a_content_type_can_override_the_site_title_template(): void
    {
        $settings = $this->settingsWithTypeDefaults();
        $resolver = $this->app->make(MetaResolver::class);

        $doc = $resolver->resolve($this->subject('d', 'Installing', raw: ['content_type' => 'doc']), null, $settings);
        $other = $resolver->resolve($this->subject('p', 'About us', raw: ['content_type' => 'page']), null, $settings);

        $this->assertSame('Installing — Acme Docs', $doc->title);
        $this->assertSame('About us - Acme', $other->title, 'a type with no override keeps the site template');
    }

    public function test_a_type_with_only_a_title_override_keeps_the_site_description_template(): void
    {
        $settings = $this->settingsWithTypeDefaults();
        $settings->type_defaults['doc'] = ['title_template' => '%%title%% — Docs'];

        $head = $this->app->make(MetaResolver::class)->resolve(
            $this->subject('d', 'Installing', 'Body text here.', ['content_type' => 'doc']),
            null,
            $settings,
        );

        $this->assertSame('Installing — Docs', $head->title);
        $this->assertSame('Body text here.', $head->metaNames['description']);
    }

    public function test_a_content_type_can_be_kept_out_of_the_index_wholesale(): void
    {
        $settings = $this->settingsWithTypeDefaults();

        $widget = $this->app->make(MetaResolver::class)->resolve(
            $this->subject('w', 'Testimonial', raw: ['content_type' => 'widget']),
            null,
            $settings,
        );

        $this->assertStringContainsString('noindex', $widget->metaNames['robots']);
    }

    public function test_a_type_default_can_never_grant_indexability_a_draft_does_not_have(): void
    {
        $settings = $this->settingsWithTypeDefaults();
        $settings->type_defaults['page'] = ['robots_index' => true];

        $draft = $this->subject('d', 'Draft')->withIndexable(false);

        $head = $this->app->make(MetaResolver::class)->resolve($draft, null, $settings);

        $this->assertStringContainsString('noindex', $head->metaNames['robots']);
    }

    public function test_type_defaults_are_read_from_the_subjects_content_type(): void
    {
        $settings = $this->settingsWithTypeDefaults();

        $this->assertSame('doc', TypeDefaults::typeOf($this->subject('d', 'T', raw: ['content_type' => 'doc'])));
        $this->assertNull(TypeDefaults::typeOf($this->subject('d', 'T')));
        $this->assertTrue(TypeDefaults::isIndexable($this->subject('d', 'T'), $settings));
    }

    public function test_prominent_words_ignore_filler_and_report_the_actual_subject(): void
    {
        $text = 'The espresso machine is the machine that makes espresso. '
            .'A good espresso machine has a pump and a boiler, and the boiler matters most.';

        $words = ProminentWords::of($text, 3);

        $this->assertSame('espresso', $words[0]['word']);
        $this->assertSame(3, $words[0]['count']);
        $this->assertNotContains('the', array_column($words, 'word'));
    }

    public function test_link_suggestions_prefer_relevant_pages_and_weight_cornerstone_ones(): void
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $repository = $this->app->make(SeoMetaRepository::class);

        $registry->register(new FakeSeoSubjectSource('pages', [
            'grinders' => $this->subject('grinders', 'Choosing a coffee grinder', 'Grinder burrs and grind size matter for coffee flavour.'),
            'beans' => $this->subject('beans', 'Coffee beans guide', 'Coffee beans, roast level and coffee freshness.'),
            'unrelated' => $this->subject('unrelated', 'Office parking policy', 'Parking permits and visitor bays for staff vehicles.'),
        ], modelClass: DocPage::class));

        $repository->upsert(DocPage::class, 'beans', ['is_cornerstone' => true]);

        $draft = 'Brewing great coffee starts with fresh coffee beans and a decent grinder. '
            .'Grind size changes how coffee tastes, and beans lose flavour quickly.';

        $suggestions = (new LinkSuggester($registry, $repository))->for($draft);

        $urls = array_column($suggestions, 'url');
        $byUrl = array_column($suggestions, null, 'url');

        $this->assertContains('https://site.test/beans', $urls);
        $this->assertContains('https://site.test/grinders', $urls);
        $this->assertNotContains('https://site.test/unrelated', $urls, 'an unrelated page shares no vocabulary');

        // Cornerstone doubles the score but does not override relevance: the
        // grinder page shares far more of this draft's vocabulary, and a
        // suggestion that ignored that would be worse advice, not better.
        $this->assertTrue($byUrl['https://site.test/beans']['cornerstone']);
        $this->assertSame(
            count($byUrl['https://site.test/beans']['shared']) * 2,
            $byUrl['https://site.test/beans']['score'],
        );
        $this->assertSame(
            count($byUrl['https://site.test/grinders']['shared']),
            $byUrl['https://site.test/grinders']['score'],
        );
    }

    public function test_cornerstone_wins_between_two_equally_relevant_pages(): void
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $repository = $this->app->make(SeoMetaRepository::class);

        $text = 'Coffee beans and roast level decide flavour.';

        $registry->register(new FakeSeoSubjectSource('pages', [
            'ordinary' => $this->subject('ordinary', 'Coffee beans A', $text),
            'flagship' => $this->subject('flagship', 'Coffee beans B', $text),
        ], modelClass: DocPage::class));

        $repository->upsert(DocPage::class, 'flagship', ['is_cornerstone' => true]);

        $suggestions = (new LinkSuggester($registry, $repository))->for($text);

        $this->assertSame('https://site.test/flagship', $suggestions[0]['url']);
    }

    public function test_pages_the_draft_already_links_to_are_not_suggested_again(): void
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $repository = $this->app->make(SeoMetaRepository::class);

        $registry->register(new FakeSeoSubjectSource('pages', [
            'beans' => $this->subject('beans', 'Coffee beans guide', 'Coffee beans, roast level and coffee freshness.'),
        ], modelClass: DocPage::class));

        $draft = 'Coffee beans lose freshness fast; buy coffee beans often.';

        $suggester = new LinkSuggester($registry, $repository);

        $this->assertNotEmpty($suggester->for($draft));
        $this->assertSame([], $suggester->for($draft, ['https://site.test/beans']));
    }

    public function test_the_page_being_edited_is_never_suggested_to_link_to_itself(): void
    {
        $registry = $this->app->make(SeoSourceRegistry::class);
        $repository = $this->app->make(SeoMetaRepository::class);

        $registry->register(new FakeSeoSubjectSource('pages', [
            'beans' => $this->subject('beans', 'Coffee beans guide', 'Coffee beans and coffee roast levels.'),
        ], modelClass: DocPage::class));

        $suggestions = (new LinkSuggester($registry, $repository))
            ->for('Coffee beans and coffee roast levels.', [], 'https://site.test/beans');

        $this->assertSame([], $suggestions);
    }
}
