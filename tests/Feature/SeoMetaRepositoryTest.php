<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;

final class SeoMetaRepositoryTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/seo';

    private SeoMetaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new SeoMetaRepository;
    }

    public function test_enabling_the_plugin_creates_the_meta_table(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('magna_seo_meta'));
    }

    public function test_upsert_creates_then_updates_a_single_row(): void
    {
        $created = $this->repository->upsert('page', '01H', ['title' => 'First']);
        $this->assertSame('First', $created->title);

        $updated = $this->repository->upsert('page', '01H', ['title' => 'Second']);
        $this->assertSame('Second', $updated->title);

        $this->assertSame(1, SeoMeta::query()->count());
        $this->assertTrue($created->is($updated));
    }

    public function test_morph_keys_cannot_be_mass_assigned(): void
    {
        $meta = new SeoMeta;
        $meta->fill([
            'seoable_type' => 'attacker',
            'seoable_id' => 'other',
            'title' => 'Legit',
        ]);

        $this->assertNull($meta->getAttribute('seoable_type'));
        $this->assertNull($meta->getAttribute('seoable_id'));
        $this->assertSame('Legit', $meta->title);
    }

    public function test_for_returns_the_matching_row_or_null(): void
    {
        $this->repository->upsert('page', '1', ['title' => 'A']);

        $this->assertSame('A', $this->repository->for('page', '1')?->title);
        $this->assertNull($this->repository->for('page', '2'));
        $this->assertNull($this->repository->for('doc', '1'));
    }

    public function test_for_many_uses_one_query_per_distinct_type(): void
    {
        $this->repository->upsert('page', '1', ['title' => 'p1']);
        $this->repository->upsert('page', '2', ['title' => 'p2']);
        $this->repository->upsert('page', '3', ['title' => 'p3']);
        $this->repository->upsert('doc', '1', ['title' => 'd1']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $map = $this->repository->forMany([
            ['page', '1'], ['page', '2'], ['page', '3'], ['doc', '1'],
        ]);

        // Two distinct types => exactly two SELECTs, regardless of id count.
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('p1', $map[$this->repository->key('page', '1')]->title);
        $this->assertSame('d1', $map[$this->repository->key('doc', '1')]->title);
        $this->assertArrayNotHasKey($this->repository->key('page', '9'), $map);
    }
}
