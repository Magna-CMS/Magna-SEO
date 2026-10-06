<?php

declare(strict_types=1);

namespace Magna\Seo\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Magna\Seo\Analysis\AnalysisCache;
use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisReport;
use Magna\Seo\Analysis\ContentAnalyser;
use Magna\Seo\Meta\MetaResolver;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Subjects\SeoSubject;

/**
 * Runs the content analysis for a single subject and prints its score and the
 * per-check findings — a live surface for the analysis engine, useful from CI or
 * a shell before the editor panel exists.
 */
final class SeoAnalyseCommand extends Command
{
    protected $signature = 'seo:analyse {source? : Source handle, e.g. docs} {id? : Subject id/slug} {--keyword= : Focus keyword} {--all : Score every page in every source and store the results}';

    protected $description = 'Analyse a page for on-page SEO against a focus keyword.';

    public function handle(SeoSourceRegistry $registry, MetaResolver $resolver, ContentAnalyser $analyser): int
    {
        if ($this->option('all')) {
            return $this->backfill($registry);
        }

        if ($this->argument('source') === null || $this->argument('id') === null) {
            $this->error('Give a source and an id, or pass --all to score every page.');

            return self::FAILURE;
        }

        $subject = $registry->resolve((string) $this->argument('source'), (string) $this->argument('id'));

        if ($subject === null) {
            $this->error('Subject not found.');

            return self::FAILURE;
        }

        $head = $resolver->resolve($subject, null, SeoSettings::get());
        $description = $head->metaNames['description'] ?? '';

        $report = $analyser->analyse(
            AnalysisInput::forSubject($subject, $description, (string) $this->option('keyword')),
        );

        $this->info("SEO score: {$report->score}/100");
        foreach ($report->results as $result) {
            $this->line("  [{$result->status->value}] {$result->check}: {$result->message}");
        }

        return self::SUCCESS;
    }

    /**
     * Score every page in every source and store the results.
     *
     * Scores are otherwise only written when a page is saved or the site is
     * scanned, so a plugin installed on a site that already has content starts
     * with an empty scoreboard — every score a dash, and the "needs work" filter
     * matching nothing. This fills it in without waiting for a full scan.
     */
    private function backfill(SeoSourceRegistry $registry): int
    {
        $cache = app(AnalysisCache::class);
        $worst = [];

        $count = $cache->refreshAll(
            $registry->all(),
            function (SeoSubject $subject, ?AnalysisReport $report) use (&$worst): void {
                if ($report !== null) {
                    $worst[] = ['title' => $subject->title, 'seo' => $report->seoScore];
                }
            },
        );

        if ($count === 0) {
            $this->warn('No content to analyse. Register a source first.');

            return self::SUCCESS;
        }

        usort($worst, static fn (array $a, array $b): int => $a['seo'] <=> $b['seo']);

        $scored = count($worst);
        $skipped = $count - $scored;

        $this->info("Scored {$scored} of {$count} ".Str::plural('page', $count).'.');

        if ($skipped > 0) {
            // Empty pages are skipped rather than scored, so say so — a silent
            // gap between "walked" and "scored" reads as a bug.
            $this->line("  {$skipped} ".Str::plural('page', $skipped).' left unscored: no body text to analyse.');
        }

        foreach (array_slice($worst, 0, 10) as $row) {
            $this->line("  {$row['seo']}/100  {$row['title']}");
        }

        if (count($worst) > 10) {
            $this->line('  … '.(count($worst) - 10).' more. The full list is on the SEO Content screen.');
        }

        return self::SUCCESS;
    }
}
