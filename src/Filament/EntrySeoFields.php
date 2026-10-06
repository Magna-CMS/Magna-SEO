<?php

declare(strict_types=1);

namespace Magna\Seo\Filament;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Magna\Content\Entry;
use Magna\Seo\Integrations\SearchConsole;
use Magna\Seo\Support\EntrySeoState;
use Magna\Seo\Url\EntryUrlResolver;

/**
 * The SEO section appended to every entry content type's admin form via
 * ExtendsEntryForm. The fields carry the per-entity override; they are
 * dehydrated(false) so they never enter the entry's own payload, and they
 * hydrate from / persist to magna_seo_meta through the tested {@see EntrySeoState}
 * on the anchor field's lifecycle hooks (afterStateHydrated / saveRelationshipsUsing).
 *
 * The mapping is unit-tested via EntrySeoState; the form render and save round-trip
 * are verified in-panel.
 */
final class EntrySeoFields
{
    /**
     * @return list<mixed>
     */
    public static function make(): array
    {
        return [
            Section::make('SEO')
                ->collapsed()
                ->columns(2)
                ->schema([
                    // Live scores first: an author opening this panel wants to
                    // know where they stand before they read a single field.
                    Livewire::make(SeoPanel::class)->columnSpanFull(),
                    View::make('seo::filament.analysis-bridge')->columnSpanFull(),

                    View::make('seo::filament.serp-preview')->columnSpanFull(),
                    View::make('seo::filament.social-preview')->columnSpanFull(),

                    TextInput::make('seo_title')
                        ->label('SEO title')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Set $set, ?Model $record): void {
                            if ($record === null) {
                                return;
                            }
                            foreach (app(EntrySeoState::class)->load($record) as $key => $value) {
                                $set($key, $value);
                            }
                        })
                        ->saveRelationshipsUsing(function (Model $record, Get $get): void {
                            $state = [];
                            foreach (EntrySeoState::FIELDS as $field) {
                                $state[$field] = $get($field);
                            }
                            app(EntrySeoState::class)->save($record, $state);
                        }),

                    Textarea::make('seo_description')->label('Meta description')->rows(2)->dehydrated(false)->columnSpanFull(),
                    TextInput::make('seo_canonical_url')->label('Canonical URL')->dehydrated(false)->columnSpanFull(),
                    Toggle::make('seo_robots_index')->label('Indexable')->default(true)->dehydrated(false),
                    Toggle::make('seo_robots_follow')->label('Follow links')->default(true)->dehydrated(false),
                    TextInput::make('seo_og_title')->label('Social title')->maxLength(255)->dehydrated(false),
                    TextInput::make('seo_twitter_card')->label('Twitter card type')->dehydrated(false),
                    Textarea::make('seo_og_description')->label('Social description')->rows(2)->dehydrated(false)->columnSpanFull(),
                    TextInput::make('seo_focus_keyword')->label('Focus keyword')->dehydrated(false)->columnSpanFull(),

                    Toggle::make('seo_is_cornerstone')
                        ->label('Cornerstone content')
                        ->helperText('Mark the handful of pages this site most wants to rank. Other pages are then suggested to link here.')
                        ->default(false)
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    // Real performance for this exact URL, when the site has
                    // connected Search Console. Hidden entirely when it has not,
                    // rather than showing an empty widget nobody asked for.
                    Text::make(fn (?Model $record): string => self::searchConsoleSummary($record))
                        ->visible(fn (): bool => app(SearchConsole::class)->isConfigured())
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * A one-line Search Console summary for the record's own URL.
     *
     * Failures are already swallowed by the integration, so the worst case here
     * is "no data yet" — an editor form must never break because a third-party
     * API is down.
     */
    private static function searchConsoleSummary(?Model $record): string
    {
        if ($record === null) {
            return 'Search Console data appears once the page has been saved.';
        }

        $handle = $record instanceof Entry ? $record->getHandle() : null;

        if ($handle === null) {
            return 'Search Console data is unavailable for this content type.';
        }

        $url = app(EntryUrlResolver::class)->forPayload($handle, $record->attributesToArray());

        if ($url === '') {
            return 'This content type has no public URL, so there is nothing to report.';
        }

        $metrics = app(SearchConsole::class)->metricsFor($url);

        if ($metrics === null) {
            return 'Search Console has no data for this URL yet.';
        }

        return sprintf(
            'Last 28 days: %d clicks, %d impressions, %.1f%% CTR, average position %.1f.',
            $metrics['clicks'],
            $metrics['impressions'],
            $metrics['ctr'] * 100,
            $metrics['position'],
        );
    }
}
