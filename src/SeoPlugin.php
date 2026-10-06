<?php

declare(strict_types=1);

namespace Magna\Seo;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;
use Magna\Content\Events\EntryCreated;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Contracts\ExtendsEntryTable;
use Magna\Contracts\RegistersAdminResources;
use Magna\Contracts\RegistersCommands;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Plugins\Plugin;
use Magna\Seo\Analysis\AnalysisCache;
use Magna\Seo\Analysis\Checks\ContentLengthCheck;
use Magna\Seo\Analysis\Checks\DescriptionLengthCheck;
use Magna\Seo\Analysis\Checks\HeadingStructureCheck;
use Magna\Seo\Analysis\Checks\ImageAltCheck;
use Magna\Seo\Analysis\Checks\KeywordDensityCheck;
use Magna\Seo\Analysis\Checks\KeywordInDescriptionCheck;
use Magna\Seo\Analysis\Checks\KeywordInFirstParagraphCheck;
use Magna\Seo\Analysis\Checks\KeywordInHeadingCheck;
use Magna\Seo\Analysis\Checks\KeywordInSlugCheck;
use Magna\Seo\Analysis\Checks\KeywordInTitleCheck;
use Magna\Seo\Analysis\Checks\LinkCountCheck;
use Magna\Seo\Analysis\Checks\PageWeightCheck;
use Magna\Seo\Analysis\Checks\ParagraphLengthCheck;
use Magna\Seo\Analysis\Checks\PassiveVoiceCheck;
use Magna\Seo\Analysis\Checks\ReadingEaseCheck;
use Magna\Seo\Analysis\Checks\SentenceLengthCheck;
use Magna\Seo\Analysis\Checks\TitleLengthCheck;
use Magna\Seo\Analysis\Checks\TransitionWordsCheck;
use Magna\Seo\Analysis\ContentAnalyser;
use Magna\Seo\Analysis\ReadingEase;
use Magna\Seo\Analysis\RecordAnalyser;
use Magna\Seo\Commands\SeoAnalyseCommand;
use Magna\Seo\Commands\SeoBingCommand;
use Magna\Seo\Commands\SeoImportCommand;
use Magna\Seo\Commands\SeoIndexNowCommand;
use Magna\Seo\Commands\SeoRedirectsCommand;
use Magna\Seo\Commands\SeoRobotsCommand;
use Magna\Seo\Commands\SeoScanCommand;
use Magna\Seo\Commands\SeoScorecardCommand;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Delivery\DeliverySeoDecorator;
use Magna\Seo\Delivery\EntryPayloadMapper;
use Magna\Seo\Filament\EntrySeoColumns;
use Magna\Seo\Filament\EntrySeoFields;
use Magna\Seo\Filament\Pages\SeoContentPage;
use Magna\Seo\Filament\Pages\SeoDashboardPage;
use Magna\Seo\Filament\Pages\SeoHealthPage;
use Magna\Seo\Filament\Pages\SeoSettingsPage;
use Magna\Seo\Filament\Pages\SeoSetupPage;
use Magna\Seo\Filament\Resources\SeoNotFoundResource;
use Magna\Seo\Filament\Resources\SeoRedirectResource;
use Magna\Seo\Filament\SeoPanel;
use Magna\Seo\Http\Middleware\HandleSeoRedirects;
use Magna\Seo\Integrations\IndexNowQueue;
use Magna\Seo\Jobs\RunSiteScanJob;
use Magna\Seo\Listeners\PingIndexNow;
use Magna\Seo\Listeners\PurgeGeneratedFiles;
use Magna\Seo\Listeners\WarnOnWeakPublish;
use Magna\Seo\Media\SocialImageResolver;
use Magna\Seo\Meta\IndexabilityPolicy;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Redirects\NotFoundLogger;
use Magna\Seo\Redirects\RedirectStore;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Scan\Checks\CanonicalConflictCheck;
use Magna\Seo\Scan\Checks\DuplicateDescriptionCheck;
use Magna\Seo\Scan\Checks\DuplicateTitleCheck;
use Magna\Seo\Scan\Checks\HeavyPageCheck;
use Magna\Seo\Scan\Checks\HreflangReciprocityCheck;
use Magna\Seo\Scan\Checks\MissingDescriptionCheck;
use Magna\Seo\Scan\Checks\MissingImageAltCheck;
use Magna\Seo\Scan\Checks\MissingTitleCheck;
use Magna\Seo\Scan\Checks\OrphanPageCheck;
use Magna\Seo\Scan\Checks\RedirectChainCheck;
use Magna\Seo\Scan\Checks\RobotsSitemapConflictCheck;
use Magna\Seo\Scan\Checks\SchemaValidationCheck;
use Magna\Seo\Scan\Checks\SitemapRedirectCheck;
use Magna\Seo\Scan\Checks\ThinContentCheck;
use Magna\Seo\Scan\ScanReporter;
use Magna\Seo\Scan\SiteScanner;
use Magna\Seo\Schema\Nodes\ArticleNodeFactory;
use Magna\Seo\Schema\Nodes\BreadcrumbNodeFactory;
use Magna\Seo\Schema\Nodes\FaqNodeFactory;
use Magna\Seo\Schema\Nodes\HowToNodeFactory;
use Magna\Seo\Schema\Nodes\IdentityNodeFactory;
use Magna\Seo\Schema\Nodes\ImageNodeFactory;
use Magna\Seo\Schema\Nodes\WebPageNodeFactory;
use Magna\Seo\Schema\Nodes\WebSiteNodeFactory;
use Magna\Seo\Schema\SchemaGraphBuilder;
use Magna\Seo\Scorecard\Scorecard;
use Magna\Seo\Sitemap\SitemapGenerator;
use Magna\Seo\Support\RequestContext;
use Magna\Seo\Url\EntryUrlResolver;
use Magna\Settings\UrlSettings;

/**
 * Entry point for the Magna SEO plugin.
 *
 * SEO is capability-detected, never hard-wired: content plugins register a
 * {@see SeoSubjectSource} into the {@see SeoSourceRegistry}
 * from their own boot(), so SEO depends on no other plugin and no other plugin
 * depends on SEO. The class stays a thin wiring shell — it owns bindings and
 * delegates the delivery decoration to {@see DeliverySeoDecorator}, holding no
 * domain logic itself.
 */
class SeoPlugin extends Plugin implements DecoratesDeliveryResponse, ExtendsEntryForm, ExtendsEntryTable, RegistersAdminResources, RegistersCommands, RegistersSettingsPages
{
    /**
     * Append the per-entity SEO panel to every content type's entry form.
     *
     * @return array<int, mixed>
     */
    public function entryFormExtensions(string $contentType): array
    {
        return EntrySeoFields::make();
    }

    /**
     * SEO and readability traffic lights in the entry list.
     *
     * @return array<int, mixed>
     */
    public function entryTableColumns(string $contentType): array
    {
        return EntrySeoColumns::columns();
    }

    /**
     * @return array<int, mixed>
     */
    public function entryTableFilters(string $contentType): array
    {
        return EntrySeoColumns::filters();
    }

    public function boot(): void
    {
        $this->loadViewsFrom('resources/views', 'seo');

        // Livewire discovers components in the application, not in plugins, so
        // the live SEO panel registers itself by name.
        Livewire::component('magna-seo-panel', SeoPanel::class);

        $this->registerAdminStyles();

        // SEO owns its own public URLs (robots.txt, sitemaps), registered so they
        // win over any renderer plugin's catch-all and work with no renderer.
        $this->loadRoutesFrom('routes/web.php', 'web');

        // Content changes invalidate the sitemaps. Editing a per-entity override
        // counts too: flipping a page to noindex must remove it from the sitemap,
        // not leave it advertised until the TTL lapses.
        foreach ([EntryCreated::class, EntryUpdated::class, EntryDeleted::class, EntryPublished::class, EntryUnpublished::class] as $event) {
            $this->listen($event, PurgeGeneratedFiles::class);
        }

        // Global, not route-group, middleware: a URL with no matching route never
        // reaches a route group at all, and that is exactly the case a redirect
        // has to cover. It still acts only on 404s, so a live page pays nothing.
        if ($this->app->make('config')->get('seo.not_found.enabled', true) !== false) {
            $this->app->make(HttpKernel::class)->pushMiddleware(HandleSeoRedirects::class);
        }

        // Tell IndexNow a URL changed. Buffered and batched by the listener, and
        // it does nothing at all until the site opts in.
        foreach ([EntryPublished::class, EntryUpdated::class, EntryUnpublished::class, EntryDeleted::class] as $event) {
            $this->listen($event, PingIndexNow::class);
        }

        // Warn the author at publish time about the problems that are cheap to
        // check and expensive to leave. Never blocks the publish.
        $this->listen(EntryPublished::class, WarnOnWeakPublish::class);

        $this->scheduleScan();

        $purge = function (): void {
            $this->app->make(PurgeGeneratedFiles::class)->purge();
        };

        SeoMeta::saved($purge);
        SeoMeta::deleted($purge);
    }

    /**
     * Register the recurring site scan, if the install wants one. It is queued
     * rather than run inline so a large site cannot stall the scheduler, and it
     * is skipped entirely when no cron expression is configured.
     */
    private function scheduleScan(): void
    {
        $expression = $this->app->make('config')->get('seo.scan.schedule');

        if (! is_string($expression) || trim($expression) === '') {
            return;
        }

        $this->app->booted(static function () use ($expression): void {
            Schedule::job(new RunSiteScanJob)->cron($expression)->name('seo:scan')->withoutOverlapping();
        });
    }

    /** @return list<class-string> */
    /**
     * Inline the plugin's own stylesheet into the admin panel.
     *
     * A Tailwind build only emits the classes it can see, and the host scans its
     * own source — not an installed plugin's views. On a distributed Magna there
     * is no npm and no way to re-run that build, so classes the core app happens
     * not to use are simply missing and the view renders unstyled, silently.
     *
     * The cascade layer matters as much as the rules. Unlayered CSS outranks
     * layered CSS whatever the order, so an unlayered sheet here beat every
     * `dark:` variant the host does ship and painted the content list's titles
     * near-black on a near-black panel. Declaring them in `components` — a layer
     * the host already opens before `utilities` — makes them a true fallback:
     * they apply where nothing else does, and lose wherever the host has an
     * opinion. Naming `utilities` instead would not work, and creating the layer
     * early is worse still: a layer takes the position it is first named at, so
     * doing this in HEAD_START pushed all of Tailwind's utilities below its own
     * reset and stripped the styling from every page in the panel.
     *
     * Inlining rather than publishing an asset is deliberate: a published file
     * needs `filament:assets` re-run after every update, and an install that
     * forgets loses its styling with no error. A few kilobytes in <head> cannot
     * be forgotten, cannot 404, and needs no build step. It is read once per
     * process, not once per request.
     */
    private function registerAdminStyles(): void
    {
        $path = __DIR__.'/../resources/css/seo.css';

        if (! is_file($path)) {
            return;
        }

        $css = (string) file_get_contents($path);

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): string => '<style data-magna-seo>'.$css.'</style>',
        );
    }

    public function settingsPages(): array
    {
        return [
            SeoDashboardPage::class,
            SeoContentPage::class,
            SeoSettingsPage::class,
            SeoHealthPage::class,
            SeoSetupPage::class,
        ];
    }

    /** @return list<class-string> */
    public function adminResources(): array
    {
        return [SeoRedirectResource::class, SeoNotFoundResource::class];
    }

    /** @return list<class-string<Command>> */
    public function commands(): array
    {
        return [
            SeoRobotsCommand::class,
            SeoRedirectsCommand::class,
            SeoScanCommand::class,
            SeoAnalyseCommand::class,
            SeoIndexNowCommand::class,
            SeoBingCommand::class,
            SeoScorecardCommand::class,
            SeoImportCommand::class,
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom('config/seo.php', 'seo');

        // One process-wide registry so source registrations from other plugins
        // accumulate for the lifetime of the request/worker.
        $this->app->singleton(SeoSourceRegistry::class);

        $this->app->bind(
            IndexabilityPolicy::class,
            fn ($app): IndexabilityPolicy => new IndexabilityPolicy(
                $app->environment('production'),
                $app->make(RequestContext::class)->isPreview(),
                $app->make(RequestContext::class)->isMaintenance(),
            ),
        );

        // Scoped: the preview/maintenance verdict is a property of the request,
        // so it is decided once and reused by every subject rendered in it.
        $this->app->scoped(RequestContext::class);

        // Scoped so the site base URL is read from settings at most once per
        // request; the resolver itself is pure, keeping delivery query-free.
        $this->app->scoped(EntryUrlResolver::class, function ($app): EntryUrlResolver {
            $patterns = $app['config']->get('seo.entry.url_patterns');
            $clean = [];

            foreach (is_array($patterns) ? $patterns : [] as $type => $pattern) {
                if (is_string($type) && is_string($pattern) && $pattern !== '') {
                    $clean[$type] = $pattern;
                }
            }

            return new EntryUrlResolver((string) UrlSettings::get()->frontend_url, $clean);
        });

        $this->app->bind(MetaResolver::class, function ($app): MetaResolver {
            $length = $app['config']->get('seo.description_length', 155);

            return new MetaResolver(
                $app->make(Meta\TemplateEngine::class),
                $app->make(IndexabilityPolicy::class),
                is_int($length) ? $length : 155,
            );
        });

        $this->app->bind(EntryPayloadMapper::class, function ($app): EntryPayloadMapper {
            $config = $app['config'];
            $strings = static fn (mixed $value): array => array_values(
                array_filter(is_array($value) ? $value : [], 'is_string'),
            );
            $locale = $config->get('app.locale', 'en');

            return new EntryPayloadMapper(
                $strings($config->get('seo.entry.title_fields')),
                $strings($config->get('seo.entry.excerpt_fields')),
                $strings($config->get('seo.entry.body_fields')),
                $strings($config->get('seo.entry.image_fields')),
                is_string($locale) && $locale !== '' ? $locale : 'en',
                $app->make(EntryUrlResolver::class),
            );
        });

        $this->app->scoped(SchemaGraphBuilder::class, fn ($app): SchemaGraphBuilder => new SchemaGraphBuilder(
            [
                new WebSiteNodeFactory,
                new IdentityNodeFactory,
                new WebPageNodeFactory,
                new ArticleNodeFactory,
                new BreadcrumbNodeFactory,
                new ImageNodeFactory,
                new FaqNodeFactory,
                new HowToNodeFactory,
            ],
            (string) UrlSettings::get()->frontend_url,
        ));

        // Scoped so the site-default social image is resolved at most once per
        // request, keeping the delivery list path query-free.
        $this->app->scoped(SocialImageResolver::class);

        // Scoped: the rule set is read once and reused across the chain walk and
        // any further lookups in the same request.
        $this->app->scoped(RedirectStore::class, function ($app): RedirectStore {
            $hops = $app['config']->get('seo.redirects.max_hops', 5);

            return new RedirectStore(is_int($hops) && $hops > 0 ? $hops : 5);
        });

        $this->app->bind(NotFoundLogger::class, function ($app): NotFoundLogger {
            $config = $app['config'];
            $rate = $config->get('seo.not_found.sample_rate', 1.0);
            $max = $config->get('seo.not_found.max_rows', 5000);
            $prefixes = $config->get('seo.not_found.ignore_prefixes');

            return new NotFoundLogger(
                is_numeric($rate) ? (float) $rate : 1.0,
                is_int($max) && $max > 0 ? $max : 5000,
                array_values(array_filter(is_array($prefixes) ? $prefixes : [], 'is_string')),
            );
        });

        // Scoped, not singleton: site settings are memoised for one request and
        // refreshed on the next, so a page of N entries costs one settings read
        // and long-lived workers (Octane) never serve stale settings.
        $this->app->scoped(DeliverySeoDecorator::class);

        $this->app->bind(ContentAnalyser::class, fn (): ContentAnalyser => new ContentAnalyser([
            new KeywordInTitleCheck,
            new KeywordInDescriptionCheck,
            new KeywordInSlugCheck,
            new KeywordInFirstParagraphCheck,
            new KeywordInHeadingCheck,
            new KeywordDensityCheck,
            new ContentLengthCheck,
            new TitleLengthCheck,
            new DescriptionLengthCheck,
            new HeadingStructureCheck,
            new LinkCountCheck,
            new ImageAltCheck,
            new ParagraphLengthCheck,
            new SentenceLengthCheck,
            new PassiveVoiceCheck,
            new TransitionWordsCheck,
            new ReadingEaseCheck(new ReadingEase),
            new PageWeightCheck,
        ]));

        $this->app->bind(IndexNowQueue::class, function ($app): IndexNowQueue {
            $window = $app['config']->get('seo.indexnow.batch_window', 3600);

            return new IndexNowQueue(is_int($window) && $window > 0 ? $window : 3600);
        });

        $this->app->bind(PingIndexNow::class, function ($app): PingIndexNow {
            $delay = $app['config']->get('seo.indexnow.batch_delay', 60);

            return new PingIndexNow(
                $app->make(IndexNowQueue::class),
                $app->make(EntryUrlResolver::class),
                is_int($delay) && $delay >= 0 ? $delay : 60,
            );
        });

        $this->app->bind(RecordAnalyser::class, function ($app): RecordAnalyser {
            $config = $app['config'];
            $strings = static fn (mixed $value): array => array_values(
                array_filter(is_array($value) ? $value : [], 'is_string'),
            );

            return new RecordAnalyser(
                $app->make(ContentAnalyser::class),
                $strings($config->get('seo.entry.title_fields')),
                $strings($config->get('seo.entry.excerpt_fields')),
                $strings($config->get('seo.entry.body_fields')),
            );
        });

        $this->app->bind(Scorecard::class, fn ($app): Scorecard => new Scorecard(
            $app->make(SeoSourceRegistry::class),
            $app->make(MetaResolver::class),
            $app->make(SchemaGraphBuilder::class),
            $app->environment('production'),
            $app->make(SitemapGenerator::class),
        ));

        $this->app->bind(SiteScanner::class, function ($app): SiteScanner {
            $thin = $app['config']->get('seo.scan.thin_content_words', 100);
            $weightConfig = $app['config']->get('seo.scan.page_weight');
            $weight = is_array($weightConfig) ? $weightConfig : [];
            $robotsPath = $app['config']->get('seo.robots.static_path');

            return new SiteScanner(
                $app->make(SeoSourceRegistry::class),
                [
                    new MissingTitleCheck,
                    new MissingDescriptionCheck,
                    new ThinContentCheck(is_int($thin) ? $thin : 100),
                    new DuplicateTitleCheck,
                    new DuplicateDescriptionCheck,
                    new HreflangReciprocityCheck,
                    new CanonicalConflictCheck,
                    new OrphanPageCheck,
                    new MissingImageAltCheck,
                    new RedirectChainCheck,
                    new SitemapRedirectCheck,
                    new RobotsSitemapConflictCheck(
                        $app->environment('production'),
                        is_string($robotsPath) ? $robotsPath : null,
                    ),
                    new SchemaValidationCheck(
                        $app->make(MetaResolver::class),
                        $app->make(SchemaGraphBuilder::class),
                    ),
                    new HeavyPageCheck(
                        is_int($weight['max_html_bytes'] ?? null) ? $weight['max_html_bytes'] : 150000,
                        is_int($weight['max_inline_bytes'] ?? null) ? $weight['max_inline_bytes'] : 50000,
                        is_int($weight['max_dom_nodes'] ?? null) ? $weight['max_dom_nodes'] : 1500,
                    ),
                ],
                $app->make(ScanReporter::class),
                $app->make(Scorecard::class),
                $app->make(AnalysisCache::class),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function decorateDeliveryEntry(string $contentType, string $entryId, array &$payload): void
    {
        $this->app->make(DeliverySeoDecorator::class)->decorate($contentType, $entryId, $payload);
    }
}
