<?php

declare(strict_types=1);

namespace Magna\Seo\Dashboard;

use Magna\Seo\Filament\Pages\SeoSettingsPage;
use Magna\Seo\Models\SeoScan;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Support\PanelUrl;
use Magna\Settings\UrlSettings;

/**
 * First-run setup, as data.
 *
 * Extracted from the setup page so the dashboard can show the same progress
 * without a second opinion about what "done" means. Every step re-derives its
 * state from the system rather than from a stored flag, so a checklist opened
 * midway through a migration is accurate rather than a record of what someone
 * clicked.
 */
final class SetupChecklist
{
    /**
     * @return list<array{id: string, title: string, done: bool, detail: string, url: string|null, action: string|null}>
     */
    public function steps(): array
    {
        $settings = SeoSettings::get();
        $urls = UrlSettings::get();
        $sources = app(SeoSourceRegistry::class)->all();
        $settingsUrl = PanelUrl::for(SeoSettingsPage::class);

        return [
            [
                'id' => 'site-url',
                'title' => 'Set the site URL',
                'done' => trim((string) $urls->frontend_url) !== '',
                'detail' => 'Canonical URLs, sitemaps and structured data are all built from this. Nothing else works properly without it.',
                'url' => null,
                'action' => null,
            ],
            [
                'id' => 'identity',
                'title' => 'Name the site and who is behind it',
                'done' => $settings->site_name !== ''
                    && ($settings->organization_name !== '' || $settings->person_name !== ''),
                'detail' => 'Used in titles and in the Organization or Person node search engines use to identify the site.',
                'url' => $settingsUrl,
                'action' => 'Open settings',
            ],
            [
                'id' => 'sources',
                'title' => 'Content is registered with SEO',
                'done' => $sources !== [],
                'detail' => $sources === []
                    ? 'No content plugin has registered a source yet, so there is nothing to optimise. Enable a content plugin first.'
                    : count($sources).' source(s) registered: '.implode(', ', array_keys($sources)).'.',
                'url' => null,
                'action' => null,
            ],
            [
                'id' => 'ai',
                'title' => 'Decide your AI crawler policy',
                'done' => $settings->setup_completed,
                'detail' => 'Training, AI search and live user fetches are three separate decisions. Blocking AI search removes you from assistant answers; blocking training does not.',
                'url' => $settingsUrl,
                'action' => 'Choose',
            ],
            [
                'id' => 'robots',
                'title' => 'Let the generated robots.txt take effect',
                'done' => ! is_file(public_path('robots.txt')),
                'detail' => 'A static public/robots.txt is served before the application and overrides everything here. Run "php artisan seo:robots" to move it aside — it renames, never deletes.',
                'url' => null,
                'action' => null,
            ],
            [
                'id' => 'scan',
                'title' => 'Run the first scan',
                'done' => SeoScan::query()->exists(),
                'detail' => 'Checks every page for the problems that keep good content from ranking, and tells you what to fix first.',
                'url' => null,
                'action' => 'Run now',
            ],
        ];
    }

    /**
     * Progress, plus the one step to do next — which is the only part small
     * enough to put on a dashboard that is already busy.
     *
     * @return array{done: int, total: int, percent: int, complete: bool, next: array{id: string, title: string, done: bool, detail: string, url: string|null, action: string|null}|null}
     */
    public function progress(): array
    {
        $steps = $this->steps();
        $outstanding = array_values(array_filter($steps, static fn (array $step): bool => ! $step['done']));

        $done = count($steps) - count($outstanding);

        return [
            'done' => $done,
            'total' => count($steps),
            'percent' => count($steps) > 0 ? (int) round($done / count($steps) * 100) : 100,
            'complete' => $outstanding === [],
            'next' => $outstanding[0] ?? null,
        ];
    }

    /**
     * Whether the dashboard should still be offering setup at all.
     *
     * Dismissal is honoured even with steps outstanding: someone who has decided
     * a step does not apply to their site should not be nagged forever.
     */
    public function shouldPrompt(): bool
    {
        return ! SeoSettings::get()->setup_completed && ! $this->progress()['complete'];
    }
}
