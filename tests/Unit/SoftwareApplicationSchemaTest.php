<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadHtmlRenderer;
use Magna\Seo\Head\HeadPayload;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Schema\Nodes\IdentityNodeFactory;
use Magna\Seo\Schema\Nodes\SoftwareApplicationNodeFactory;
use Magna\Seo\Schema\Nodes\WebPageNodeFactory;
use Magna\Seo\Schema\Nodes\WebSiteNodeFactory;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Schema\SchemaValidator;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use PHPUnit\Framework\TestCase;

/**
 * The SoftwareApplication node is editor input turned into structured data, so
 * most of what is asserted here is what it refuses: unknown fields, unsafe
 * URLs, malformed prices — and any rating at all.
 */
final class SoftwareApplicationSchemaTest extends TestCase
{
    private function builder(): SchemaGraphBuilder
    {
        return new SchemaGraphBuilder([
            new WebSiteNodeFactory,
            new IdentityNodeFactory,
            new WebPageNodeFactory,
            new SoftwareApplicationNodeFactory,
        ], 'https://site.test');
    }

    private function subject(string $url = 'https://site.test/'): SeoSubject
    {
        return new SeoSubject(
            key: 'page:1',
            type: SubjectType::Home,
            url: $url,
            title: 'Home',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-11-03T00:00:00+00:00'),
        );
    }

    private function head(string $canonical = 'https://site.test/'): HeadPayload
    {
        return new HeadPayload(title: 'Home', metaNames: ['robots' => 'index, follow'], links: ['canonical' => $canonical]);
    }

    private function settings(): SeoSettings
    {
        $settings = new SeoSettings;
        $settings->site_name = 'Acme';
        $settings->organization_name = 'Acme Studios';

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function override(array $app): SeoMeta
    {
        return new SeoMeta(['schema_overrides' => [SoftwareApplicationNodeFactory::KEY => $app]]);
    }

    /**
     * @param  array<string, mixed>|null  $graph
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

    public function test_nothing_is_emitted_without_an_override(): void
    {
        $graph = $this->builder()->build($this->subject(), $this->head(), $this->settings());

        $this->assertNull($this->node($graph, 'SoftwareApplication'));
    }

    public function test_nothing_is_emitted_without_an_app_name(): void
    {
        $graph = $this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'application_category' => 'FinanceApplication',
        ]));

        $this->assertNull($this->node($graph, 'SoftwareApplication'));
    }

    public function test_the_whitelisted_fields_become_a_typed_node(): void
    {
        $graph = $this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'name' => 'Acme',
            'alternate_names' => ['Acme billing', 'Acme app'],
            'application_category' => 'FinanceApplication',
            'application_subcategory' => 'Billing',
            'operating_systems' => ['Android', 'Windows'],
            'software_version' => '0.3.0',
            'download_url' => '/download',
            'offers' => [
                ['name' => 'Free', 'price' => '0', 'currency' => 'inr'],
                ['name' => 'Pro', 'price' => 499, 'currency' => 'INR'],
            ],
            'in_language' => ['en', 'hi'],
            'feature_list' => ['Offline billing', 'GST invoices'],
        ]));

        $app = $this->node($graph, 'SoftwareApplication');

        $this->assertNotNull($app);
        $this->assertSame('https://site.test/#software-acme', $app['@id']);
        $this->assertSame('Acme', $app['name']);
        $this->assertSame(['Acme billing', 'Acme app'], $app['alternateName']);
        $this->assertSame('FinanceApplication', $app['applicationCategory']);
        $this->assertSame('Billing', $app['applicationSubCategory']);
        $this->assertSame('Android, Windows', $app['operatingSystem']);
        $this->assertSame('0.3.0', $app['softwareVersion']);
        $this->assertSame('https://site.test/download', $app['downloadUrl']);
        $this->assertSame([
            ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR', 'name' => 'Free'],
            ['@type' => 'Offer', 'price' => '499', 'priceCurrency' => 'INR', 'name' => 'Pro'],
        ], $app['offers']);
        $this->assertSame(['en', 'hi'], $app['inLanguage']);
        $this->assertSame(['Offline billing', 'GST invoices'], $app['featureList']);
        $this->assertSame(['@id' => 'https://site.test/#identity'], $app['publisher']);

        $this->assertSame([], (new SchemaValidator)->validate($graph));
    }

    public function test_two_pages_describe_one_app(): void
    {
        $override = $this->override(['name' => 'Acme']);

        $home = $this->node($this->builder()->build($this->subject(), $this->head(), $this->settings(), $override), 'SoftwareApplication');
        $download = $this->node($this->builder()->build(
            $this->subject('https://site.test/download'),
            $this->head('https://site.test/download'),
            $this->settings(),
            $override,
        ), 'SoftwareApplication');

        $this->assertNotNull($home);
        $this->assertNotNull($download);
        $this->assertSame($home['@id'], $download['@id']);
        $this->assertSame($home['url'], $download['url']);
    }

    public function test_a_rating_or_any_unknown_field_never_comes_through(): void
    {
        $app = $this->node($this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'name' => 'Acme',
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '5', 'ratingCount' => '999'],
            'aggregate_rating' => ['rating_value' => 5],
            'review' => ['reviewBody' => 'Great'],
            '@type' => 'Product',
            'application_category' => 'MadeUpApplication',
        ])), 'SoftwareApplication');

        $this->assertNotNull($app);
        $this->assertSame('SoftwareApplication', $app['@type']);
        $this->assertArrayNotHasKey('aggregateRating', $app);
        $this->assertArrayNotHasKey('review', $app);
        $this->assertArrayNotHasKey('applicationCategory', $app);
    }

    public function test_unsafe_urls_and_malformed_offers_are_dropped(): void
    {
        $app = $this->node($this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'name' => 'Acme',
            'download_url' => 'javascript:alert(1)',
            'install_url' => '//evil.test/app',
            'screenshot' => 'data:image/png;base64,AAAA',
            'offers' => [
                ['name' => 'Free', 'price' => 'free', 'currency' => 'INR'],
                ['name' => 'Pro', 'price' => '-5', 'currency' => 'INR'],
                ['name' => 'Odd', 'price' => '10', 'currency' => 'RUPEES'],
                ['name' => 'Linked', 'price' => '10', 'currency' => 'INR', 'url' => 'javascript:void(0)'],
            ],
        ])), 'SoftwareApplication');

        $this->assertNotNull($app);
        $this->assertArrayNotHasKey('downloadUrl', $app);
        $this->assertArrayNotHasKey('installUrl', $app);
        $this->assertArrayNotHasKey('screenshot', $app);
        $this->assertSame([['@type' => 'Offer', 'price' => '10', 'priceCurrency' => 'INR', 'name' => 'Linked']], $app['offers']);
    }

    public function test_values_are_length_capped(): void
    {
        $app = $this->node($this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'name' => str_repeat('a', 500),
            'feature_list' => array_map(static fn (int $i): string => 'Feature '.$i, range(1, 50)),
        ])), 'SoftwareApplication');

        $this->assertNotNull($app);
        $this->assertSame(120, mb_strlen($app['name']));
        $this->assertCount(30, $app['featureList']);
    }

    public function test_markup_in_a_value_cannot_escape_the_script_element(): void
    {
        $graph = $this->builder()->build($this->subject(), $this->head(), $this->settings(), $this->override([
            'name' => 'Acme</script><script>alert(1)</script>',
        ]));
        $this->assertNotNull($graph);

        $html = (new HeadHtmlRenderer)->render($this->head()->withJsonLd([$graph]));

        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function test_the_validator_rejects_a_self_written_rating(): void
    {
        $errors = (new SchemaValidator)->validate([
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'SoftwareApplication',
                'name' => 'Acme',
                'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '5'],
            ]],
        ]);

        $this->assertNotSame([], $errors);
    }

    public function test_the_website_node_carries_the_alternate_name(): void
    {
        $settings = $this->settings();
        $settings->website_alternate_name = 'Acme billing software';

        $site = $this->node($this->builder()->build($this->subject(), $this->head(), $settings), 'WebSite');

        $this->assertNotNull($site);
        $this->assertSame('Acme billing software', $site['alternateName']);
        $this->assertSame(['@id' => 'https://site.test/#identity'], $site['publisher']);
    }
}
