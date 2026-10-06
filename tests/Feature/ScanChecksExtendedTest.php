<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Jobs\RunSiteScanJob;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\MatchType;
use Magna\Seo\Scan\Checks\CanonicalConflictCheck;
use Magna\Seo\Scan\Checks\MissingImageAltCheck;
use Magna\Seo\Scan\Checks\OrphanPageCheck;
use Magna\Seo\Scan\Checks\RedirectChainCheck;
use Magna\Seo\Scan\Checks\RobotsSitemapConflictCheck;
use Magna\Seo\Scan\Checks\SchemaValidationCheck;
use Magna\Seo\Scan\Checks\SitemapRedirectCheck;
use Magna\Seo\Scan\ScannedSubject;
use Magna\Seo\Scan\Severity;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Testing\PluginTestCase;

/**
 * The scan checks added to close S8 — the ones that need the redirect table, the
 * link graph, or the structured-data builder.
 */
final class ScanChecksExtendedTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function subject(string $key, string $url, array $raw = [], string $title = 'Title'): ScannedSubject
    {
        return new ScannedSubject('fake', new SeoSubject(
            key: $key,
            type: SubjectType::Page,
            url: $url,
            title: $title,
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-08-18T00:00:00+00:00'),
            raw: $raw,
        ));
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

    public function test_orphan_pages_are_flagged_only_when_a_link_graph_exists(): void
    {
        $check = new OrphanPageCheck;

        $linked = $this->subject('a', 'https://site.test/a', ['html' => '<a href="/b">to b</a>']);
        $target = $this->subject('b', 'https://site.test/b', ['html' => '<p>no links</p>']);
        $orphan = $this->subject('c', 'https://site.test/c', ['html' => '<p>alone</p>']);

        $issues = $check->run([$linked, $target, $orphan]);
        $keys = array_map(static fn ($issue): string => $issue->subjectKey, $issues);

        $this->assertContains('c', $keys);
        $this->assertContains('a', $keys);
        $this->assertNotContains('b', $keys);

        // Without markup anywhere there is no graph, so nothing is claimed.
        $this->assertSame([], $check->run([
            $this->subject('x', 'https://site.test/x'),
            $this->subject('y', 'https://site.test/y'),
        ]));
    }

    public function test_two_pages_sharing_a_canonical_are_both_flagged(): void
    {
        $issues = (new CanonicalConflictCheck)->run([
            $this->subject('a', 'https://site.test/same'),
            $this->subject('b', 'https://site.test/same'),
            $this->subject('c', 'https://site.test/other'),
        ]);

        $this->assertCount(2, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function test_images_without_alt_text_are_reported(): void
    {
        $issues = (new MissingImageAltCheck)->run([
            $this->subject('a', 'https://site.test/a', ['html' => '<img src="x.jpg"><img src="y.jpg" alt="">']),
            $this->subject('b', 'https://site.test/b', ['html' => '<img src="z.jpg" alt="Described">']),
        ]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('1 image(s)', $issues[0]->message);
    }

    public function test_redirect_chains_and_loops_are_reported(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/c');
        $this->rule('/loop-1', '/loop-2');
        $this->rule('/loop-2', '/loop-1');
        $this->rule('/direct', '/final');

        $issues = (new RedirectChainCheck)->run([]);
        $byCheck = [];
        foreach ($issues as $issue) {
            $byCheck[$issue->check][] = $issue->url;
        }

        $this->assertContains('/a', $byCheck['redirect-chain'] ?? []);
        $this->assertNotContains('/direct', $byCheck['redirect-chain'] ?? []);
        $this->assertNotEmpty($byCheck['redirect-loop'] ?? []);
    }

    public function test_a_sitemap_url_that_redirects_is_an_error(): void
    {
        $this->rule('/moved', '/new-home');

        $issues = (new SitemapRedirectCheck)->run([
            $this->subject('a', 'https://site.test/moved'),
            $this->subject('b', 'https://site.test/fine'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('a', $issues[0]->subjectKey);
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function test_a_static_robots_file_that_blocks_everything_is_an_error(): void
    {
        $path = sys_get_temp_dir().'/seo-robots-test.txt';
        file_put_contents($path, "User-agent: *\nDisallow: /\n");

        $issues = (new RobotsSitemapConflictCheck(isProduction: true, staticRobotsPath: $path))
            ->run([$this->subject('a', 'https://site.test/a')]);

        unlink($path);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString('seo:robots', $issues[0]->message);
    }

    public function test_a_static_robots_file_without_a_sitemap_pointer_is_a_warning(): void
    {
        $path = sys_get_temp_dir().'/seo-robots-nositemap.txt';
        file_put_contents($path, "User-agent: *\nAllow: /\n");

        $issues = (new RobotsSitemapConflictCheck(isProduction: true, staticRobotsPath: $path))
            ->run([$this->subject('a', 'https://site.test/a')]);

        unlink($path);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
    }

    public function test_no_static_robots_file_means_no_finding(): void
    {
        $issues = (new RobotsSitemapConflictCheck(isProduction: false, staticRobotsPath: '/does/not/exist'))
            ->run([$this->subject('a', 'https://site.test/a')]);

        $this->assertSame([], $issues);
    }

    public function test_valid_structured_data_produces_no_findings(): void
    {
        $check = new SchemaValidationCheck(
            $this->app->make(MetaResolver::class),
            $this->app->make(SchemaGraphBuilder::class),
        );

        $this->assertSame([], $check->run([$this->subject('a', 'https://site.test/a')]));
    }

    public function test_the_scan_can_be_queued_instead_of_run_inline(): void
    {
        Queue::fake();

        $this->artisan('seo:scan --queue')->assertSuccessful();

        Queue::assertPushed(RunSiteScanJob::class);
    }
}
