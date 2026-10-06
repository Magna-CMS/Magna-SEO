<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Magna\Contracts\ExtendsEntryTable;
use Magna\Docs\Models\DocPage;
use Magna\Seo\Filament\EntrySeoColumns;
use Magna\Seo\SeoPlugin;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;

/**
 * The entry-list seam: SEO and readability traffic lights contributed to the
 * admin table through the new ExtendsEntryTable contract.
 */
final class EntryTableColumnsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('magna-cms/seo');
        $this->enablePlugin('magna/docs');
        EntrySeoColumns::flush();
    }

    /**
     * The plugin as the panel sees it — taken from the container accumulation
     * rather than constructed, since a Plugin needs its base path to exist.
     */
    private function plugin(): ExtendsEntryTable
    {
        /** @var list<ExtendsEntryTable> $plugins */
        $plugins = $this->app->make('magna.entry_table_plugins');

        foreach ($plugins as $plugin) {
            if ($plugin instanceof SeoPlugin) {
                return $plugin;
            }
        }

        $this->fail('The SEO plugin was not accumulated as an entry-table extender.');
    }

    private function page(string $slug): DocPage
    {
        return DocPage::create([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'content' => 'Body.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function test_the_plugin_declares_the_table_contract_and_is_accumulated(): void
    {
        $this->assertInstanceOf(SeoPlugin::class, $this->plugin());

        $this->assertTrue(
            $this->app->bound('magna.entry_table_plugins'),
            'PluginManager accumulates table-extending plugins for EntryResource',
        );
    }

    public function test_it_contributes_both_score_columns_and_the_three_filters(): void
    {
        $plugin = $this->plugin();

        $this->assertCount(2, $plugin->entryTableColumns('post'));
        $this->assertCount(3, $plugin->entryTableFilters('post'));
    }

    public function test_scores_are_read_from_cache_with_one_query_for_the_whole_page(): void
    {
        $repository = $this->app->make(SeoMetaRepository::class);

        $pages = [];
        foreach (['a', 'b', 'c'] as $slug) {
            $pages[$slug] = $this->page($slug);
            $repository->upsert(DocPage::class, (string) $pages[$slug]->getKey(), [
                'analysis_cache' => ['seo_score' => 80, 'readability_score' => 40],
            ]);
        }

        EntrySeoColumns::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Reading every row must cost one query, not one per row — that is the
        // difference between a list and a timeout.
        foreach ($pages as $page) {
            $this->assertSame(80, EntrySeoColumns::scoreOf($page, 'seo_score'));
            $this->assertSame(40, EntrySeoColumns::scoreOf($page, 'readability_score'));
        }

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_an_unanalysed_entry_has_no_score_rather_than_a_zero(): void
    {
        $page = $this->page('never-analysed');

        // Zero would read as "scored badly"; null is "not measured", and the
        // column renders it as a dash.
        $this->assertNull(EntrySeoColumns::scoreOf($page, 'seo_score'));
    }
}
