<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Media\Media;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Filament\Pages\SeoSettingsPage;
use Magna\Seo\Head\SeoHeadRenderer;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Robots\StaticFileTakeover;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Settings\UrlSettings;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use Magna\Users\UserStatus;

/**
 * SEO 1.2.0's site identity: the organisation logo and site icon chosen from
 * the media library, the root icon URLs, handing static files over without a
 * terminal, and telling page caches when anything SEO prints has changed.
 */
final class SiteIdentityTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $urls = UrlSettings::get();
        $urls->frontend_url = 'https://site.test';
        $urls->save();

        $this->public = storage_path('framework/testing/seo-public-'.uniqid());
        File::ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);

        parent::tearDown();
    }

    private function image(string $name, int $width, int $height): Media
    {
        return Media::create([
            'disk' => 'public',
            'path' => $name,
            'filename' => $name,
            'original_filename' => $name,
            'mime_type' => 'image/png',
            'size' => 20480,
            'width' => $width,
            'height' => $height,
        ]);
    }

    private function subject(): SeoSubject
    {
        return new SeoSubject(
            key: 'page:home',
            type: SubjectType::Home,
            url: 'https://site.test/',
            title: 'Home',
            locale: 'en',
            indexable: true,
            updatedAt: new DateTimeImmutable('2026-11-03T00:00:00+00:00'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function organization(): ?array
    {
        $head = $this->app->make(SeoHeadRenderer::class)->head($this->subject());

        foreach ($head->jsonLd[0]['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === 'Organization') {
                return $node;
            }
        }

        return null;
    }

    private function operator(): User
    {
        $role = Role::query()->create(['handle' => 'seo-identity', 'name' => 'SEO identity', 'is_super_admin' => true]);
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'identity@example.test',
            'password' => 'password',
            'status' => UserStatus::Active->value,
        ]);
        $user->roles()->attach($role);

        return $user->refresh();
    }

    public function test_the_organisation_logo_is_an_image_object_with_its_dimensions(): void
    {
        $logo = $this->image('logo.png', 512, 512);
        $settings = SeoSettings::get();
        $settings->organization_name = 'Acme Studios';
        $settings->organization_logo = $logo->id;
        $settings->save();

        $organization = $this->organization();

        $this->assertNotNull($organization);
        $this->assertSame('ImageObject', $organization['logo']['@type']);
        $this->assertSame('https://site.test/#logo', $organization['logo']['@id']);
        $this->assertSame(512, $organization['logo']['width']);
        $this->assertSame(512, $organization['logo']['height']);
        $this->assertStringEndsWith('logo.png', $organization['logo']['url']);
        $this->assertSame(['@id' => 'https://site.test/#logo'], $organization['image']);
    }

    public function test_a_logo_path_typed_before_the_picker_still_works_and_a_bad_scheme_does_not(): void
    {
        $settings = SeoSettings::get();
        $settings->organization_name = 'Acme Studios';
        $settings->organization_logo = 'brand/logo.png';
        $settings->save();

        $organization = $this->organization();
        $this->assertNotNull($organization);
        $this->assertStringStartsWith('http', $organization['logo']['url']);
        $this->assertStringEndsWith('brand/logo.png', $organization['logo']['url']);

        $this->app->forgetScopedInstances();
        $settings->organization_logo = 'javascript:alert(1)';
        $settings->save();

        $this->assertArrayNotHasKey('logo', $this->organization() ?? []);
    }

    public function test_the_site_icon_reaches_the_head_and_the_root_icon_urls(): void
    {
        $icon = $this->image('icon.png', 512, 512);
        $settings = SeoSettings::get();
        $settings->site_icon = $icon->id;
        $settings->save();

        $html = $this->app->make(SeoHeadRenderer::class)->render($this->subject());
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]+icon\.png">#', $html);
        $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="[^"]+icon\.png">#', $html);

        $favicon = $this->get('/favicon.ico');
        $favicon->assertStatus(301);
        $this->assertStringEndsWith('icon.png', (string) $favicon->headers->get('Location'));

        $this->get('/apple-touch-icon.png')->assertStatus(301);
    }

    public function test_the_root_icon_urls_are_404_without_an_icon(): void
    {
        $this->get('/favicon.ico')->assertNotFound();
        $this->get('/apple-touch-icon.png')->assertNotFound();
    }

    public function test_robots_txt_points_at_an_absolute_sitemap(): void
    {
        $this->app['env'] = 'production';

        $body = (string) $this->get('/robots.txt')->getContent();

        $this->assertMatchesRegularExpression('#^Sitemap: https?://[^/\s]+/sitemap\.xml$#m', $body);
    }

    public function test_static_files_are_moved_aside_never_deleted_and_can_be_restored(): void
    {
        File::put($this->public.'/robots.txt', "User-agent: *\nDisallow:\n");
        File::put($this->public.'/favicon.ico', '');

        $takeover = new StaticFileTakeover;
        $this->assertSame(['robots.txt', 'favicon.ico'], $takeover->pending());

        $this->assertSame(['robots.txt' => 'moved', 'favicon.ico' => 'moved'], $takeover->takeOver());
        $this->assertFileDoesNotExist($this->public.'/robots.txt');
        $this->assertSame("User-agent: *\nDisallow:\n", File::get($this->public.'/robots.txt.magna-backup'));
        $this->assertSame([], $takeover->pending());

        // A second static file appearing (a core update restoring it) must not
        // overwrite the first backup.
        File::put($this->public.'/robots.txt', 'new');
        $this->assertSame('kept', $takeover->takeOver()['robots.txt']);
        $this->assertSame("User-agent: *\nDisallow:\n", File::get($this->public.'/robots.txt.magna-backup'));
        File::delete($this->public.'/robots.txt');

        $this->assertSame(['robots.txt' => 'restored', 'favicon.ico' => 'restored'], $takeover->restore());
        $this->assertSame("User-agent: *\nDisallow:\n", File::get($this->public.'/robots.txt'));
    }

    public function test_the_command_hands_both_files_over(): void
    {
        File::put($this->public.'/robots.txt', 'static');
        File::put($this->public.'/favicon.ico', '');

        $this->artisan('seo:robots')
            ->expectsOutputToContain('Moved static robots.txt')
            ->expectsOutputToContain('Moved static favicon.ico')
            ->assertSuccessful();

        $this->assertFileExists($this->public.'/favicon.ico.magna-backup');

        $this->artisan('seo:robots', ['--restore' => true])->assertSuccessful();
        $this->assertFileExists($this->public.'/robots.txt');
    }

    public function test_the_settings_screen_offers_the_takeover_only_while_a_file_is_in_the_way(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(SeoSettingsPage::class)->assertActionHidden('takeOverStaticFiles');

        File::put($this->public.'/favicon.ico', '');

        Livewire::test(SeoSettingsPage::class)
            ->assertActionVisible('takeOverStaticFiles')
            ->callAction('takeOverStaticFiles')
            ->assertNotified('SEO now serves favicon.ico.');

        $this->assertFileExists($this->public.'/favicon.ico.magna-backup');
        $this->assertFileDoesNotExist($this->public.'/favicon.ico');
    }

    public function test_the_settings_screen_saves_the_new_identity_fields(): void
    {
        $this->actingAs($this->operator());
        $icon = $this->image('icon.png', 512, 512);
        $logo = $this->image('logo.png', 512, 512);
        $social = $this->image('og.png', 1200, 630);

        Livewire::test(SeoSettingsPage::class)
            ->assertSee('Site icon')
            ->set('data.site_icon', $icon->id)
            ->set('data.organization_logo', $logo->id)
            ->set('data.default_og_image', $social->id)
            ->set('data.website_alternate_name', 'Acme billing software')
            ->set('data.theme_color', '#0B1220')
            ->callAction('save')
            ->assertHasNoFormErrors();

        $settings = SeoSettings::get();
        $this->assertSame($icon->id, $settings->site_icon);
        $this->assertSame($logo->id, $settings->organization_logo);
        $this->assertSame($social->id, $settings->default_og_image);
        $this->assertSame('Acme billing software', $settings->website_alternate_name);
        $this->assertSame('#0B1220', $settings->theme_color);
    }

    public function test_a_legacy_logo_path_does_not_block_saving_the_screen(): void
    {
        $this->actingAs($this->operator());
        $settings = SeoSettings::get();
        $settings->organization_logo = 'brand/logo.png';
        $settings->save();

        Livewire::test(SeoSettingsPage::class)
            ->set('data.site_name', 'Acme')
            ->callAction('save')
            ->assertHasNoFormErrors();

        $this->assertSame('brand/logo.png', SeoSettings::get()->organization_logo);
        $this->assertSame('Acme', SeoSettings::get()->site_name);
    }

    public function test_saving_settings_or_an_override_tells_page_caches(): void
    {
        Event::fake([PublicContentChanged::class]);

        $settings = SeoSettings::get();
        $settings->site_name = 'Acme';
        $settings->save();

        Event::assertDispatched(PublicContentChanged::class, fn (PublicContentChanged $event): bool => $event->everything);

        Event::fake([PublicContentChanged::class]);

        $meta = new SeoMeta(['title' => 'Custom']);
        $meta->setAttribute('seoable_type', 'page');
        $meta->setAttribute('seoable_id', '01HZZZZZZZZZZZZZZZZZZZZZZZ');
        $meta->save();

        Event::assertDispatched(PublicContentChanged::class);
    }
}
