<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Seo\Models\SeoNotFound;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\MatchType;
use Magna\Seo\Redirects\NotFoundLogger;
use Magna\Seo\Redirects\RedirectCsv;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Testing\PluginTestCase;

/**
 * The S6 exit gate: chains collapse, loops are broken rather than followed, and
 * the 404 table cannot grow without bound.
 */
final class RedirectsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(string $from, ?string $to, array $attributes = []): SeoRedirect
    {
        return SeoRedirect::query()->create(array_merge([
            'source_path' => $from,
            'target' => $to,
            'match_type' => MatchType::Exact->value,
            'status_code' => 301,
            'preserve_query' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function store(int $maxHops = 5): RedirectStore
    {
        return new RedirectStore($maxHops);
    }

    public function test_an_exact_rule_matches_regardless_of_slash_and_case_of_separators(): void
    {
        $this->rule('/old-page/', '/new-page');

        foreach (['/old-page', '/old-page/', '//old-page//'] as $path) {
            $match = $this->store()->match($path);

            $this->assertNotNull($match, $path);
            $this->assertSame('/new-page', $match->target);
            $this->assertSame(301, $match->status);
        }
    }

    public function test_the_query_string_is_carried_over_unless_the_rule_says_otherwise(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/c', '/d', ['preserve_query' => false]);

        $this->assertSame('/b?utm=x', $this->store()->match('/a', 'utm=x')?->target);
        $this->assertSame('/d', $this->store()->match('/c', 'utm=x')?->target);
    }

    public function test_a_query_specific_rule_beats_the_plain_path_rule(): void
    {
        $this->rule('/product', '/catalogue');
        $this->rule('/product?id=5&v=2', '/catalogue/five');

        $this->assertSame('/catalogue/five', $this->store()->match('/product', 'id=5&v=2')?->target);
        // Parameter order must not matter.
        $this->assertSame('/catalogue/five', $this->store()->match('/product', 'v=2&id=5')?->target);
        // A different query falls through to the plain path rule.
        $this->assertSame('/catalogue?id=6', $this->store()->match('/product', 'id=6')?->target);
    }

    public function test_a_chain_is_collapsed_to_its_final_destination(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/c');
        $this->rule('/c', '/d');

        $match = $this->store()->match('/a');

        $this->assertSame('/d', $match?->target);
        $this->assertSame(3, $match?->hops);
    }

    public function test_a_loop_stops_instead_of_recursing(): void
    {
        $this->rule('/x', '/y');
        $this->rule('/y', '/x');

        $match = $this->store()->match('/x');

        $this->assertNotNull($match);
        $this->assertSame('/x', $match->target);
        $this->assertSame(2, $match->hops);
    }

    public function test_the_hop_ceiling_bounds_a_long_chain(): void
    {
        foreach (range(1, 10) as $step) {
            $this->rule("/step{$step}", '/step'.($step + 1));
        }

        $match = $this->store(maxHops: 3)->match('/step1');

        $this->assertSame(3, $match?->hops);
    }

    public function test_a_regex_rule_substitutes_capture_groups(): void
    {
        $this->rule('^/legacy/(.+)$', '/articles/$1', ['match_type' => MatchType::Regex->value]);

        $this->assertSame('/articles/hello', $this->store()->match('/legacy/hello')?->target);
    }

    public function test_an_uncompilable_pattern_is_skipped_not_fatal(): void
    {
        $this->rule('^/broken((', '/x', ['match_type' => MatchType::Regex->value]);
        $this->rule('^/works$', '/y', ['match_type' => MatchType::Regex->value]);

        $this->assertNull($this->store()->match('/broken'));
        $this->assertSame('/y', $this->store()->match('/works')?->target);
    }

    public function test_a_gone_rule_has_no_target(): void
    {
        $this->rule('/removed', null, ['status_code' => 410]);

        $match = $this->store()->match('/removed');

        $this->assertTrue($match?->isGone());
        $this->assertNull($match?->target);
    }

    public function test_a_dangerous_target_is_refused(): void
    {
        $this->rule('/evil', 'javascript:alert(1)');

        $this->assertNull($this->store()->match('/evil')?->target);
    }

    public function test_an_inactive_rule_never_matches(): void
    {
        $this->rule('/off', '/on', ['is_active' => false]);

        $this->assertNull($this->store()->match('/off'));
    }

    public function test_a_missing_page_is_logged_once_per_path_and_counted(): void
    {
        $logger = new NotFoundLogger;

        $logger->record('/gone', 'https://ref.test/page');
        $logger->record('/gone');

        $row = SeoNotFound::query()->firstOrFail();

        $this->assertSame('/gone', $row->path);
        $this->assertSame(2, $row->hits);
        $this->assertSame('https://ref.test/page', $row->last_referrer);
        $this->assertSame(1, SeoNotFound::query()->count());
    }

    public function test_a_hostile_referrer_is_not_stored(): void
    {
        (new NotFoundLogger)->record('/gone', 'javascript:alert(1)');

        $this->assertNull(SeoNotFound::query()->firstOrFail()->last_referrer);
    }

    public function test_ignored_prefixes_are_never_logged(): void
    {
        (new NotFoundLogger(ignorePrefixes: ['/admin']))->record('/admin/missing');

        $this->assertSame(0, SeoNotFound::query()->count());
    }

    public function test_the_404_table_cannot_exceed_its_cap(): void
    {
        $logger = new NotFoundLogger(maxRows: 5);

        foreach (range(1, 20) as $n) {
            $logger->record("/missing-{$n}");
        }

        $this->assertLessThanOrEqual(5, SeoNotFound::query()->count());
        // The most recent paths are the ones kept.
        $this->assertNotNull(SeoNotFound::query()->where('path', '/missing-20')->first());
    }

    public function test_a_404_request_is_redirected_and_the_hit_is_counted(): void
    {
        $rule = $this->rule('/moved', '/somewhere-else');

        $this->get('/moved')->assertRedirect('/somewhere-else')->assertStatus(301);

        $this->assertSame(1, $rule->refresh()->hits);
    }

    public function test_a_404_without_a_rule_is_logged_and_still_a_404(): void
    {
        $this->get('/nothing-here')->assertNotFound();

        $this->assertNotNull(SeoNotFound::query()->where('path', '/nothing-here')->first());
    }

    public function test_csv_import_validates_rows_and_updates_rather_than_duplicates(): void
    {
        $this->rule('/existing', '/old-target');

        $result = (new RedirectCsv)->import([
            ['source', 'target', 'status', 'match_type', 'preserve_query', 'is_active', 'notes'],
            ['/existing', '/new-target', '301', 'exact', '1', '1', 'updated'],
            ['/fresh', '/somewhere', '302', 'exact', '0', '1', ''],
            ['/dead', '', '410', 'exact', '1', '1', ''],
            ['', '/nowhere', '301', 'exact', '1', '1', ''],
            ['/bad-status', '/x', '200', 'exact', '1', '1', ''],
            ['/no-target', '', '301', 'exact', '1', '1', ''],
            ['/bad-type', '/x', '301', 'wildcard', '1', '1', ''],
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(4, $result['skipped']);
        $this->assertCount(4, $result['errors']);

        $this->assertSame('/new-target', SeoRedirect::query()->where('source_path', '/existing')->firstOrFail()->target);
        $this->assertNull(SeoRedirect::query()->where('source_path', '/dead')->firstOrFail()->target);
    }

    public function test_a_yoast_or_rank_math_redirect_export_imports_as_is(): void
    {
        $yoast = (new RedirectCsv)->import([
            ['origin', 'target', 'type', 'format'],
            ['/yoast-old', '/yoast-new', '301', 'plain'],
        ]);

        $rankMath = (new RedirectCsv)->import([
            ['sources', 'url_to', 'header_code', 'status'],
            ['/rm-old', '/rm-new', '302', 'active'],
        ]);

        $this->assertSame(1, $yoast['imported']);
        $this->assertSame(1, $rankMath['imported']);
        $this->assertSame('/yoast-new', SeoRedirect::query()->where('source_path', '/yoast-old')->firstOrFail()->target);
        $this->assertSame(302, SeoRedirect::query()->where('source_path', '/rm-old')->firstOrFail()->status_code);
    }

    public function test_csv_export_round_trips_through_import(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/c', null, ['status_code' => 410]);

        $rows = (new RedirectCsv)->export();

        SeoRedirect::query()->delete();
        $result = (new RedirectCsv)->import($rows);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, SeoRedirect::query()->count());
    }

    public function test_the_headless_hint_endpoint_reports_a_match_without_counting_it(): void
    {
        $rule = $this->rule('/api-moved', '/api-new');

        $this->getJson('/seo/redirect-hint?path=/api-moved')
            ->assertOk()
            ->assertJsonPath('redirect.to', '/api-new')
            ->assertJsonPath('redirect.status', 301);

        $this->assertSame(0, $rule->refresh()->hits);

        $this->getJson('/seo/redirect-hint?path=/still-missing')->assertNotFound();
        $this->getJson('/seo/redirect-hint')->assertStatus(422);
    }
}
