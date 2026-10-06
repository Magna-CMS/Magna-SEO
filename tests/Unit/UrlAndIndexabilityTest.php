<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Unit;

use DateTimeImmutable;
use Magna\Seo\Delivery\EntryIndexability;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Url\EntryUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The S1 exit gate: canonical URL and robots directive asserted across the full
 * matrix of content states, so a regression in either shows up as a table row
 * rather than as a silently de-indexed site.
 */
final class UrlAndIndexabilityTest extends TestCase
{
    private const NOW = '2026-08-18T12:00:00+00:00';

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function urlCases(): array
    {
        return [
            'default pattern' => [['slug' => 'hello'], 'https://example.com/post/hello'],
            'missing slug yields no url' => [['id' => '1'], ''],
            'blank slug yields no url' => [['slug' => '   '], ''],
            'slug is url-encoded' => [['slug' => 'a b'], 'https://example.com/post/a%20b'],
            'traversal in slug cannot escape its segment' => [['slug' => '../../etc'], 'https://example.com/post/..%2F..%2Fetc'],
            'query injection in slug is encoded' => [['slug' => 'x?a=1'], 'https://example.com/post/x%3Fa%3D1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('urlCases')]
    public function test_entry_urls(array $payload, string $expected): void
    {
        $resolver = new EntryUrlResolver('https://example.com/', ['*' => '/{type}/{slug}']);

        $this->assertSame($expected, $resolver->forPayload('post', $payload));
    }

    public function test_per_type_patterns_and_date_tokens(): void
    {
        $resolver = new EntryUrlResolver('https://example.com', [
            'news' => '/{year}/{month}/{day}/{slug}',
            'post' => '/blog/{slug}',
        ]);

        $payload = ['slug' => 'launch', 'published_at' => '2026-03-04T09:00:00+00:00'];

        $this->assertSame('https://example.com/2026/03/04/launch', $resolver->forPayload('news', $payload));
        $this->assertSame('https://example.com/blog/launch', $resolver->forPayload('post', $payload));
    }

    public function test_a_type_without_a_pattern_has_no_public_url(): void
    {
        $resolver = new EntryUrlResolver('https://example.com', ['post' => '/blog/{slug}']);

        $this->assertSame('', $resolver->forPayload('setting', ['slug' => 'x']));
        $this->assertFalse($resolver->hasPatternFor('setting'));
        $this->assertTrue($resolver->hasPatternFor('post'));
    }

    public function test_no_base_url_means_no_canonical(): void
    {
        $resolver = new EntryUrlResolver('', ['*' => '/{slug}']);

        $this->assertSame('', $resolver->forPayload('post', ['slug' => 'hello']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}>
     */
    public static function indexabilityCases(): array
    {
        $past = '2026-01-01T00:00:00+00:00';
        $future = '2027-01-01T00:00:00+00:00';

        return [
            'published with url' => [['status' => 'published'], true, true],
            'published without url' => [['status' => 'published'], false, false],
            'draft' => [['status' => 'draft'], true, false],
            'scheduled status' => [['status' => 'scheduled'], true, false],
            'archived' => [['status' => 'archived'], true, false],
            'missing status' => [[], true, false],
            'published in the past' => [['status' => 'published', 'published_at' => $past], true, true],
            'published dated forward' => [['status' => 'published', 'published_at' => $future], true, false],
            'unpublish date passed' => [['status' => 'published', 'unpublish_at' => $past], true, false],
            'unpublish date ahead' => [['status' => 'published', 'unpublish_at' => $future], true, true],
            'unparseable dates are ignored' => [['status' => 'published', 'published_at' => 'not-a-date'], true, true],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('indexabilityCases')]
    public function test_entry_indexability(array $payload, bool $hasUrl, bool $expected): void
    {
        $verdict = (new EntryIndexability)->isIndexable($payload, $hasUrl, new DateTimeImmutable(self::NOW));

        $this->assertSame($expected, $verdict);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: bool, 5: string}>
     */
    public static function robotsCases(): array
    {
        //           subject, production, preview, maintenance, override-noindex, expected
        return [
            'live page in production' => [true, true, false, false, false, 'index, follow'],
            'non-indexable subject' => [false, true, false, false, false, 'noindex, follow'],
            'staging' => [true, false, false, false, false, 'noindex, follow'],
            'preview in production' => [true, true, true, false, false, 'noindex, follow'],
            'preview cannot be re-indexed by an override' => [true, true, true, false, false, 'noindex, follow'],
            'maintenance mode' => [true, true, false, true, false, 'noindex, follow'],
            'editor noindex' => [true, true, false, false, true, 'noindex, follow'],
            'editor cannot re-index a draft' => [false, true, false, false, false, 'noindex, follow'],
        ];
    }

    #[DataProvider('robotsCases')]
    public function test_robots_directive(
        bool $subjectIndexable,
        bool $isProduction,
        bool $isPreview,
        bool $isMaintenance,
        bool $overrideNoindex,
        string $expected,
    ): void {
        $policy = new IndexabilityPolicy($isProduction, $isPreview, $isMaintenance);
        $override = $overrideNoindex ? new SeoMeta(['robots_index' => false]) : null;

        $this->assertSame(
            $expected,
            $policy->robotsContent($this->subject($subjectIndexable), $override, new SeoSettings),
        );
    }

    public function test_an_override_can_never_re_index_a_preview(): void
    {
        $policy = new IndexabilityPolicy(isProduction: true, isPreview: true);
        $override = new SeoMeta(['robots_index' => true, 'robots_follow' => true]);

        $this->assertSame(
            'noindex, follow',
            $policy->robotsContent($this->subject(true), $override, new SeoSettings),
        );
    }

    private function subject(bool $indexable): SeoSubject
    {
        return new SeoSubject(
            key: 'entry:post:1',
            type: SubjectType::Page,
            url: 'https://example.com/post/hello',
            title: 'Hello',
            locale: 'en',
            indexable: $indexable,
            updatedAt: new DateTimeImmutable(self::NOW),
        );
    }
}
