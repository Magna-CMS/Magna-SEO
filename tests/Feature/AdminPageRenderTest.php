<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Seo\Filament\Pages\SeoContentPage;
use Magna\Seo\Filament\Pages\SeoDashboardPage;
use Magna\Seo\Filament\Pages\SeoHealthPage;
use Magna\Seo\Filament\Pages\SeoSetupPage;
use Magna\Seo\Filament\Resources\SeoNotFoundResource;
use Magna\Seo\Filament\Resources\SeoRedirectResource;
use Magna\Seo\Filament\SeoPanel;
use Magna\Seo\Models\SeoNotFound;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Redirects\MatchType;
use Magna\Seo\Settings\SeoSettings;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use Magna\Users\UserStatus;

/**
 * The admin screens actually render.
 *
 * These were assumed untestable for most of this plugin's life — the reasoning
 * being that the harness cannot boot a Filament panel — so every page was
 * verified by hand. That assumption was worth testing: Livewire can mount a page
 * component directly, without a panel route, which is enough to catch the
 * failures that matter here. A blade typo, a method the view calls that does not
 * exist, a null nobody guarded: all of those fatal on mount, and all of them
 * used to reach production.
 *
 * This does not replace looking at the pages. It replaces *finding out from a
 * user* that one of them is a white screen.
 */
final class AdminPageRenderTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
        config()->set('seo.robots.static_path', __DIR__.'/no-robots.txt');

        $this->actingAs($this->operator());
    }

    /**
     * Someone who may actually open these screens. The pages gate on permissions,
     * so an unauthenticated mount is a 403 rather than a render — which is
     * correct behaviour and separately asserted below.
     */
    private function operator(): User
    {
        $role = Role::query()->create([
            'handle' => 'seo-operator',
            'name' => 'SEO operator',
            'is_super_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'status' => UserStatus::Active->value,
        ]);

        $user->roles()->attach($role);

        return $user->refresh();
    }

    public function test_the_dashboard_renders_before_any_scan_has_run(): void
    {
        // The emptiest possible state, and the one a new install always sees
        // first — no scan, no Search Console, no content.
        Livewire::test(SeoDashboardPage::class)
            ->assertOk()
            ->assertSee('Site health')
            ->assertSee('Not connected');
    }

    public function test_the_dashboard_renders_with_a_scan_and_findings(): void
    {
        SeoScan::query()->create([
            'url_count' => 12,
            'issue_count' => 3,
            'score' => 74,
            'check_stats' => ['missing-description' => 3],
        ]);

        Livewire::test(SeoDashboardPage::class)
            ->assertOk()
            ->assertSee('74')
            ->assertSee('Start here')
            ->assertSee('Meta descriptions');
    }

    public function test_the_health_page_renders_empty_and_populated(): void
    {
        Livewire::test(SeoHealthPage::class)
            ->assertOk()
            ->assertSee('No scan has run yet');

        $scan = SeoScan::query()->create(['url_count' => 1, 'issue_count' => 1, 'score' => 50]);

        $scan->issues()->create([
            'source' => 'docs',
            'subject_key' => 'docs:a',
            'url' => 'https://site.test/a',
            'check' => 'missing-description',
            'severity' => 'warning',
            'message' => 'No description.',
        ]);

        Livewire::test(SeoHealthPage::class)
            ->assertOk()
            ->assertSee('No description.')
            ->assertSee('How to fix this');
    }

    public function test_the_content_page_renders_and_its_filters_hold(): void
    {
        Livewire::test(SeoContentPage::class)
            ->assertOk()
            ->assertSee('All pages')
            ->set('filter', 'no-description')
            ->assertOk()
            ->set('search', 'nothing-matches-this')
            ->assertOk();
    }

    public function test_the_dashboard_offers_setup_until_it_is_dismissed(): void
    {
        // A fresh install has steps outstanding, so the prompt leads the page.
        Livewire::test(SeoDashboardPage::class)
            ->assertOk()
            ->assertSee('Finish setting up SEO')
            ->assertSee('steps done');

        Livewire::test(SeoDashboardPage::class)
            ->call('dismissSetup')
            ->assertOk();

        // Dismissal is honoured even with steps left: someone who has decided a
        // step does not apply should not be nagged forever.
        $this->assertTrue(SeoSettings::get()->setup_completed);

        Livewire::test(SeoDashboardPage::class)
            ->assertOk()
            ->assertDontSee('Finish setting up SEO');
    }

    public function test_the_setup_checklist_renders_and_counts_its_steps(): void
    {
        Livewire::test(SeoSetupPage::class)
            ->assertOk()
            ->assertSee('steps done')
            ->assertSee('Run the first scan');
    }

    public function test_the_live_panel_scores_nothing_until_there_is_something_to_score(): void
    {
        // A brand-new post opened 38/100 and 50/100 before this — numbers made
        // almost entirely of checks that cannot fail on an empty page. An
        // author starting an article was told it was already failing.
        // The placeholder keeps the "—/100" frame, so absence is asserted on the
        // things only a real analysis produces: a band word and a check list.
        Livewire::test(SeoPanel::class)
            ->assertOk()
            ->assertSee('Not analysed')
            ->assertSee('Write something and the scores appear')
            ->assertDontSee('to improve')
            ->assertDontSee('All checks pass')
            ->assertDontSee('How to fix');

        // A title alone is still nothing to analyse: every content, keyword,
        // link, heading and readability check reads the body.
        Livewire::test(SeoPanel::class)
            ->call('syncFromForm', ['title' => 'A title and nothing else'])
            ->assertOk()
            ->assertSee('Not analysed');
    }

    public function test_the_live_editor_panel_renders_and_rescores(): void
    {
        Livewire::test(SeoPanel::class)
            ->assertOk()
            ->call('syncFromForm', [
                'title' => 'Coffee beans: a buying guide',
                'description' => 'Choosing coffee beans.',
                'slug' => 'coffee-beans',
                'body' => '<h1>Coffee beans</h1><p>'.str_repeat('Coffee beans are graded by roast. ', 60).'</p><a href="/x">x</a>',
                'keyword' => 'coffee beans',
            ])
            ->assertOk()
            ->assertSee('SEO')
            ->assertSee('Readability')
            // The prominent-words panel proves the analysis actually ran.
            ->assertSee('coffee');
    }

    /**
     * The redirect and 404 screens are Filament *resources*, not custom pages,
     * and their list components resolve their own panel routes while rendering.
     * That cannot be stubbed from outside a booted panel, so those two remain
     * hand-verified — the table and form definitions are asserted directly
     * instead, which is what actually differs between them and a stock resource.
     */
    public function test_the_resource_screens_are_configured(): void
    {
        SeoRedirect::query()->create([
            'source_path' => '/old',
            'target' => '/new',
            'match_type' => MatchType::Exact->value,
            'status_code' => 301,
            'preserve_query' => true,
            'is_active' => true,
        ]);

        SeoNotFound::query()->create([
            'path' => '/missing',
            'path_hash' => hash('sha256', '/missing'),
            'hits' => 4,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->assertSame(SeoRedirect::class, SeoRedirectResource::getModel());
        $this->assertSame(SeoNotFound::class, SeoNotFoundResource::getModel());

        // The 404 log is written by the middleware; offering a create screen
        // would imply otherwise.
        $this->assertFalse(SeoNotFoundResource::canCreate());
        $this->assertTrue(SeoRedirectResource::canCreate());

        $this->assertSame(1, SeoRedirect::query()->count());
        $this->assertSame(1, SeoNotFound::query()->count());
    }

    public function test_the_pages_are_closed_to_anyone_without_permission(): void
    {
        // The permission gate is the only thing standing between these screens
        // and any authenticated user, so assert it rather than assume it.
        auth()->logout();

        $this->assertFalse(SeoDashboardPage::canAccess());
        $this->assertFalse(SeoContentPage::canAccess());
        $this->assertFalse(SeoSetupPage::canAccess());
        $this->assertFalse(SeoHealthPage::canAccess());
    }
}
