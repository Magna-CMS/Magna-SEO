<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Schema\Nodes\ArticleNodeFactory;
use Magna\Seo\Schema\Nodes\BreadcrumbNodeFactory;
use Magna\Seo\Schema\Nodes\FaqNodeFactory;
use Magna\Seo\Schema\Nodes\HowToNodeFactory;
use Magna\Seo\Schema\Nodes\IdentityNodeFactory;
use Magna\Seo\Schema\Nodes\ImageNodeFactory;
use Magna\Seo\Schema\Nodes\WebPageNodeFactory;
use Magna\Seo\Schema\Nodes\WebSiteNodeFactory;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoAuthor;
use Magna\Seo\Subjects\SeoCrumb;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

final class SchemaGraphBuilderTest extends TestCase
{
    private function builder(string $baseUrl = 'https://site.test'): SchemaGraphBuilder
    {
        return new SchemaGraphBuilder([
            new WebSiteNodeFactory,
            new IdentityNodeFactory,
            new WebPageNodeFactory,
            new ArticleNodeFactory,
            new BreadcrumbNodeFactory,
            new ImageNodeFactory,
            new FaqNodeFactory,
            new HowToNodeFactory,
        ], $baseUrl);
    }

    /**
     * @param  list<SeoImage>  $images
     * @param  list<SeoCrumb>  $breadcrumbs
     */
    private function subject(
        SubjectType $type = SubjectType::Page,
        array $images = [],
        ?SeoAuthor $author = null,
        array $breadcrumbs = [],
    ): SeoSubject {
        return new SeoSubject(
            key: 'k',
            type: $type,
            url: 'https://site.test/p',
            title: 'Content Title',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            publishedAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
            images: $images,
            breadcrumbs: $breadcrumbs,
            author: $author,
        );
    }

    private function head(?string $canonical = 'https://site.test/p', ?string $description = 'Desc'): HeadPayload
    {
        $names = ['robots' => 'index, follow'];
        if ($description !== null) {
            $names['description'] = $description;
        }
        $links = $canonical !== null ? ['canonical' => $canonical] : [];

        return new HeadPayload(title: 'SEO Title', metaNames: $names, links: $links);
    }

    private function orgSettings(): SeoSettings
    {
        $settings = new SeoSettings;
        $settings->site_name = 'Acme';
        $settings->knowledge_graph_type = 'organization';
        $settings->organization_name = 'Acme Inc';
        $settings->social_profiles = ['https://x.com/acme'];

        return $settings;
    }

    /**
     * @param  array{'@context': string, '@graph': list<array<string, mixed>>}|null  $graph
     * @return array<string, mixed>|null
     */
    private function node(?array $graph, string $type): ?array
    {
        foreach ($graph['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    public function test_a_page_graph_links_website_identity_image_and_page(): void
    {
        $image = new SeoImage('https://cdn.test/a.jpg', width: 1200, height: 630, alt: 'Alt');
        $graph = $this->builder()->build($this->subject(images: [$image]), $this->head(), $this->orgSettings());

        $this->assertNotNull($graph);
        $this->assertSame('https://schema.org', $graph['@context']);

        $website = $this->node($graph, 'WebSite');
        $identity = $this->node($graph, 'Organization');
        $page = $this->node($graph, 'WebPage');
        $imageNode = $this->node($graph, 'ImageObject');

        $this->assertNotNull($website);
        $this->assertNotNull($identity);
        $this->assertNotNull($page);
        $this->assertNotNull($imageNode);

        $this->assertSame('https://site.test/#website', $website['@id']);
        $this->assertSame(['@id' => 'https://site.test/#identity'], $website['publisher']);
        $this->assertSame(['https://x.com/acme'], $identity['sameAs']);

        $this->assertSame('https://site.test/p#webpage', $page['@id']);
        $this->assertSame('https://site.test/p', $page['url']);
        $this->assertSame('Content Title', $page['name']);
        $this->assertSame('Desc', $page['description']);
        $this->assertSame(['@id' => 'https://site.test/#website'], $page['isPartOf']);

        // WebPage points at the very ImageObject the graph carries.
        $this->assertSame($imageNode['@id'], $page['primaryImageOfPage']['@id']);
        $this->assertSame('https://site.test/p#primaryimage', $imageNode['@id']);
        $this->assertSame(1200, $imageNode['width']);
        $this->assertSame('Alt', $imageNode['caption']);

        $this->assertNull($this->node($graph, 'BlogPosting'));
        $this->assertNull($this->node($graph, 'BreadcrumbList'));
    }

    public function test_an_article_graph_adds_article_and_breadcrumbs(): void
    {
        $graph = $this->builder()->build(
            $this->subject(
                type: SubjectType::Article,
                author: new SeoAuthor('Jane', url: 'https://site.test/author/jane'),
                breadcrumbs: [new SeoCrumb('Home', 'https://site.test/'), new SeoCrumb('Post')],
            ),
            $this->head(),
            $this->orgSettings(),
        );

        $article = $this->node($graph, 'BlogPosting');
        $crumbs = $this->node($graph, 'BreadcrumbList');

        $this->assertNotNull($article);
        $this->assertSame('Content Title', $article['headline']);
        $this->assertSame(['@id' => 'https://site.test/p#webpage'], $article['mainEntityOfPage']);
        $this->assertSame(['@id' => 'https://site.test/#identity'], $article['publisher']);
        $this->assertSame('Jane', $article['author']['name']);
        $this->assertSame('2026-08-01T00:00:00+00:00', $article['datePublished']);

        $this->assertNotNull($crumbs);
        $this->assertCount(2, $crumbs['itemListElement']);
        $this->assertSame(1, $crumbs['itemListElement'][0]['position']);
        $this->assertSame('https://site.test/', $crumbs['itemListElement'][0]['item']);
        $this->assertArrayNotHasKey('item', $crumbs['itemListElement'][1]);
    }

    public function test_faq_and_howto_nodes_are_built_from_raw(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Page,
            url: 'https://site.test/p',
            title: 'Guide',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-15T00:00:00+00:00'),
            raw: [
                'faq' => [
                    ['question' => 'What is it?', 'answer' => 'A thing.'],
                    ['question' => '', 'answer' => 'skipped — no question'],
                ],
                'howto' => [
                    ['text' => 'Do the first thing.'],
                    ['name' => 'Second', 'text' => 'Do the second thing.'],
                ],
            ],
        );

        $graph = $this->builder()->build($subject, $this->head(), new SeoSettings);

        $faq = $this->node($graph, 'FAQPage');
        $this->assertNotNull($faq);
        $this->assertCount(1, $faq['mainEntity']); // the empty-question entry is skipped
        $this->assertSame('What is it?', $faq['mainEntity'][0]['name']);
        $this->assertSame('A thing.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertSame('https://site.test/p#faq', $faq['@id']);

        $howto = $this->node($graph, 'HowTo');
        $this->assertNotNull($howto);
        $this->assertSame('Guide', $howto['name']);
        $this->assertCount(2, $howto['step']);
        $this->assertSame('Do the first thing.', $howto['step'][0]['text']);
        $this->assertSame('Second', $howto['step'][1]['name']);
    }

    public function test_minimal_subject_without_base_identity_or_canonical(): void
    {
        $graph = $this->builder(baseUrl: '')->build($this->subject(), $this->head(canonical: null, description: null), new SeoSettings);

        $this->assertNotNull($graph);
        $this->assertNull($this->node($graph, 'WebSite'));
        $this->assertNull($this->node($graph, 'Organization'));

        $page = $this->node($graph, 'WebPage');
        $this->assertNotNull($page);
        $this->assertArrayNotHasKey('@id', $page);
        $this->assertArrayNotHasKey('url', $page);
        $this->assertArrayNotHasKey('isPartOf', $page);
        $this->assertArrayNotHasKey('description', $page);
        $this->assertSame('Content Title', $page['name']);
    }

    public function test_a_doc_subject_is_published_as_a_tech_article(): void
    {
        $graph = $this->builder()->build($this->subject(type: SubjectType::Doc), $this->head(), $this->orgSettings());

        $this->assertNotNull($this->node($graph, 'TechArticle'));
        $this->assertNull($this->node($graph, 'BlogPosting'));
    }

    public function test_a_source_may_name_a_more_precise_article_subtype(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Article,
            url: 'https://site.test/p',
            title: 'Breaking',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            raw: ['schema_type' => 'NewsArticle'],
        );

        $graph = $this->builder()->build($subject, $this->head(), $this->orgSettings());

        $this->assertNotNull($this->node($graph, 'NewsArticle'));
    }

    public function test_an_unknown_requested_schema_type_is_ignored(): void
    {
        $subject = new SeoSubject(
            key: 'k',
            type: SubjectType::Article,
            url: 'https://site.test/p',
            title: 'T',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            raw: ['schema_type' => 'Malicious'],
        );

        $graph = $this->builder()->build($subject, $this->head(), $this->orgSettings());

        $this->assertNotNull($this->node($graph, 'BlogPosting'));
        $this->assertNull($this->node($graph, 'Malicious'));
    }

    public function test_the_website_node_advertises_a_search_action_when_configured(): void
    {
        $settings = $this->orgSettings();
        $settings->search_url_template = 'https://site.test/search?q={search_term_string}';

        $site = $this->node($this->builder()->build($this->subject(), $this->head(), $settings), 'WebSite');

        $this->assertNotNull($site);
        $this->assertSame('SearchAction', $site['potentialAction'][0]['@type']);
        $this->assertSame('required name=search_term_string', $site['potentialAction'][0]['query-input']);
    }

    public function test_a_search_template_without_the_placeholder_is_not_emitted(): void
    {
        $settings = $this->orgSettings();
        $settings->search_url_template = 'https://site.test/search';

        $site = $this->node($this->builder()->build($this->subject(), $this->head(), $settings), 'WebSite');

        $this->assertNotNull($site);
        $this->assertArrayNotHasKey('potentialAction', $site);
    }
}
