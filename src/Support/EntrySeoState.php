<?php

declare(strict_types=1);

namespace Magna\Seo\Support;

use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Analysis\RecordAnalyser;
use Magna\Seo\Schema\Nodes\SoftwareApplicationNodeFactory;

/**
 * Translates between the SEO editor panel's form state and the magna_seo_meta
 * override for a content model. This is the testable core of the entry-form SEO
 * panel: the Filament components only hydrate from load() and persist through
 * save(), so the mapping and the write are verified independently of the panel's
 * render.
 */
final class EntrySeoState
{
    /** Keys shared by the form state and this mapper. */
    public const FIELDS = [
        'seo_title', 'seo_description', 'seo_canonical_url',
        'seo_robots_index', 'seo_robots_follow',
        'seo_og_title', 'seo_og_description', 'seo_twitter_card', 'seo_focus_keyword',
        'seo_is_cornerstone',
        ...self::APP_FIELDS,
    ];

    /**
     * The "Software application" structured-data fields, stored under
     * schema_overrides['software_application'] (see SoftwareApplicationNodeFactory).
     * List-valued fields travel as lists; offers as one "Name | price | currency"
     * line each, which is what the editor types.
     */
    public const APP_FIELDS = [
        'seo_app_name', 'seo_app_alternate_names', 'seo_app_category',
        'seo_app_operating_systems', 'seo_app_version', 'seo_app_download_url',
        'seo_app_offers', 'seo_app_features', 'seo_app_languages', 'seo_app_screenshot',
    ];

    public function __construct(
        private readonly SeoMetaRepository $repository,
        private readonly ?RecordAnalyser $analyser = null,
    ) {}

    /**
     * The form state for a record, defaulting to a blank override when none exists.
     *
     * @return array<string, mixed>
     */
    public function load(Model $record): array
    {
        $meta = $this->repository->forModel($record);

        if ($meta === null) {
            return [
                'seo_title' => null,
                'seo_description' => null,
                'seo_canonical_url' => null,
                'seo_robots_index' => true,
                'seo_robots_follow' => true,
                'seo_og_title' => null,
                'seo_og_description' => null,
                'seo_twitter_card' => null,
                'seo_focus_keyword' => null,
                'seo_is_cornerstone' => false,
                ...$this->loadApp(null),
            ];
        }

        $keywords = $meta->focus_keywords ?? [];

        return [
            'seo_title' => $meta->title,
            'seo_description' => $meta->description,
            'seo_canonical_url' => $meta->canonical_url,
            'seo_robots_index' => $meta->robots_index,
            'seo_robots_follow' => $meta->robots_follow,
            'seo_og_title' => $meta->og_title,
            'seo_og_description' => $meta->og_description,
            'seo_twitter_card' => $meta->twitter_card,
            'seo_focus_keyword' => $keywords[0] ?? null,
            'seo_is_cornerstone' => $meta->is_cornerstone,
            ...$this->loadApp($meta->schema_overrides[SoftwareApplicationNodeFactory::KEY] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadApp(mixed $app): array
    {
        $app = is_array($app) ? $app : [];
        $list = static fn (mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        $offers = [];
        foreach (is_array($app['offers'] ?? null) ? $app['offers'] : [] as $offer) {
            if (is_array($offer)) {
                $offers[] = implode(' | ', array_filter([
                    is_string($offer['name'] ?? null) ? $offer['name'] : '',
                    is_scalar($offer['price'] ?? null) ? (string) $offer['price'] : '',
                    is_string($offer['currency'] ?? null) ? $offer['currency'] : '',
                ], static fn (string $part): bool => $part !== ''));
            }
        }

        return [
            'seo_app_name' => $string($app['name'] ?? null),
            'seo_app_alternate_names' => $list($app['alternate_names'] ?? null),
            'seo_app_category' => $string($app['application_category'] ?? null),
            'seo_app_operating_systems' => $list($app['operating_systems'] ?? null),
            'seo_app_version' => $string($app['software_version'] ?? null),
            'seo_app_download_url' => $string($app['download_url'] ?? null),
            'seo_app_offers' => $offers === [] ? null : implode("\n", $offers),
            'seo_app_features' => $list($app['feature_list'] ?? null),
            'seo_app_languages' => $list($app['in_language'] ?? null),
            'seo_app_screenshot' => $string($app['screenshot'] ?? null),
        ];
    }

    /**
     * The panel's software-application fields as the stored shape, or null when
     * no app name was given — a node without a name is not emitted, so storing
     * the rest would only be clutter.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function saveApp(array $state): ?array
    {
        $name = $this->clean($state, 'seo_app_name');
        if ($name === null) {
            return null;
        }

        $list = static fn (mixed $value): array => array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', is_array($value) ? $value : []),
            static fn (string $item): bool => $item !== '',
        ));

        $offers = [];
        foreach (preg_split('/\R/', (string) ($state['seo_app_offers'] ?? '')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) === 1 && $parts[0] === '') {
                continue;
            }

            // "Free | 0 | INR", or "0 | INR" for an unnamed offer.
            if (count($parts) === 2) {
                array_unshift($parts, '');
            }

            $offers[] = array_filter([
                'name' => $parts[0],
                'price' => $parts[1] ?? '',
                'currency' => strtoupper($parts[2] ?? ''),
            ], static fn (string $value): bool => $value !== '');
        }

        return array_filter([
            'name' => $name,
            'alternate_names' => $list($state['seo_app_alternate_names'] ?? null),
            'application_category' => $this->clean($state, 'seo_app_category'),
            'operating_systems' => $list($state['seo_app_operating_systems'] ?? null),
            'software_version' => $this->clean($state, 'seo_app_version'),
            'download_url' => $this->clean($state, 'seo_app_download_url'),
            'offers' => $offers,
            'feature_list' => $list($state['seo_app_features'] ?? null),
            'in_language' => $list($state['seo_app_languages'] ?? null),
            'screenshot' => $this->clean($state, 'seo_app_screenshot'),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Persist the panel state as the record's override. A wholly-empty panel does
     * not create a row (an empty override is identical to none), but it does clear
     * an existing one.
     *
     * @param  array<string, mixed>  $state
     */
    public function save(Model $record, array $state): void
    {
        $keyword = trim((string) ($state['seo_focus_keyword'] ?? ''));
        $index = (bool) ($state['seo_robots_index'] ?? true);
        $follow = (bool) ($state['seo_robots_follow'] ?? true);
        $cornerstone = (bool) ($state['seo_is_cornerstone'] ?? false);

        $attributes = [
            'title' => $this->clean($state, 'seo_title'),
            'description' => $this->clean($state, 'seo_description'),
            'canonical_url' => $this->clean($state, 'seo_canonical_url'),
            'robots_index' => $index,
            'robots_follow' => $follow,
            'og_title' => $this->clean($state, 'seo_og_title'),
            'og_description' => $this->clean($state, 'seo_og_description'),
            'twitter_card' => $this->clean($state, 'seo_twitter_card'),
            'focus_keywords' => $keyword !== '' ? [$keyword] : null,
            'is_cornerstone' => $cornerstone,
        ];

        $existing = $this->repository->forModel($record);

        // Other keys in schema_overrides belong to other features (or to a
        // newer version of this one); only the app key is this panel's to write.
        $overrides = is_array($existing?->schema_overrides) ? $existing->schema_overrides : [];
        unset($overrides[SoftwareApplicationNodeFactory::KEY]);
        $app = $this->saveApp($state);
        if ($app !== null) {
            $overrides[SoftwareApplicationNodeFactory::KEY] = $app;
        }
        $attributes['schema_overrides'] = $overrides === [] ? null : $overrides;

        $isBlank = $index && $follow && ! $cornerstone
            && $attributes['title'] === null
            && $attributes['description'] === null
            && $attributes['canonical_url'] === null
            && $attributes['og_title'] === null
            && $attributes['og_description'] === null
            && $attributes['twitter_card'] === null
            && $attributes['focus_keywords'] === null
            && $attributes['schema_overrides'] === null;

        if ($isBlank && $existing === null) {
            return;
        }

        // Analysis runs here, on save, so the render path never pays for it and
        // reopening the editor shows the last result immediately.
        if ($this->analyser !== null) {
            $report = $this->analyser->analyse(
                $record,
                $keyword,
                $attributes['title'],
                $attributes['description'],
            );

            $attributes['analysis_cache'] = $report->toArray();
            $attributes['analysis_computed_at'] = now();
        }

        $this->repository->upsert($record->getMorphClass(), (string) $record->getKey(), $attributes);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function clean(array $state, string $key): ?string
    {
        $value = trim((string) ($state[$key] ?? ''));

        return $value !== '' ? $value : null;
    }
}
