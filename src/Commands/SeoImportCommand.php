<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;
use Magna\Seo\Import\SeoImporter;
use Magna\Seo\Import\SeoMetaMapper;
use Magna\Seo\Import\WxrParser;
use Magna\Seo\Import\WxrPost;
use Throwable;

/**
 * Imports SEO meta from a WordPress WXR export.
 *
 * Dry run by default, and deliberately so: an import writes over per-page SEO
 * fields, and the slug match that pairs a WordPress post with a Magna entry is a
 * guess the operator should see before it is acted on. `--apply` performs it.
 */
final class SeoImportCommand extends Command
{
    protected $signature = 'seo:import
        {file : Path to a WordPress WXR (.xml) export}
        {--type= : Content type handle to match posts against, by slug}
        {--apply : Write the mapped meta instead of only reporting it}';

    protected $description = 'Import SEO meta from a WordPress WXR export (Yoast, Rank Math, AIOSEO, SEOPress).';

    public function handle(WxrParser $parser, SeoMetaMapper $mapper, SeoImporter $importer, SchemaRegistry $registry): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $posts = $parser->parse((string) file_get_contents($file));

        if ($posts === []) {
            $this->warn('No posts found — the file may not be a valid WXR export.');

            return self::SUCCESS;
        }

        $importable = array_values(array_filter(
            $posts,
            static fn (WxrPost $post): bool => $mapper->map($post->postmeta) !== null,
        ));

        $this->info('Parsed '.count($posts).' posts; '.count($importable).' carry SEO meta this importer understands.');

        $type = $this->option('type');

        if (! is_string($type) || $type === '') {
            $this->line('Pass --type=<content type handle> to match posts against your content, and --apply to write.');

            return self::SUCCESS;
        }

        return $this->pair($importable, $type, $registry, $importer, $mapper);
    }

    /**
     * Match each post to an entry by slug and, with --apply, write its meta.
     *
     * @param  list<WxrPost>  $posts
     */
    private function pair(array $posts, string $type, SchemaRegistry $registry, SeoImporter $importer, SeoMetaMapper $mapper): int
    {
        try {
            $prototype = Entry::makeInstance($type, $registry);
        } catch (Throwable $e) {
            $this->error("Unknown content type \"{$type}\": ".$e->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $matched = 0;
        /** @var list<string> $missing */
        $missing = [];

        foreach ($posts as $post) {
            if ($post->slug === '') {
                continue;
            }

            $entry = $prototype->newQuery()->where('slug', $post->slug)->first();

            if (! $entry instanceof Entry) {
                $missing[] = $post->slug;

                continue;
            }

            $matched++;

            if ($apply) {
                $importer->import($post->postmeta, $entry);
            }
        }

        $this->info(($apply ? 'Imported ' : 'Would import ')."{$matched} of ".count($posts)." post(s) onto \"{$type}\" entries.");

        if ($missing !== []) {
            $this->warn(count($missing).' post(s) had no matching entry, e.g. '.implode(', ', array_slice($missing, 0, 5)));
        }

        if (! $apply) {
            $this->line('Dry run — re-run with --apply to write these overrides.');
        }

        return self::SUCCESS;
    }
}
