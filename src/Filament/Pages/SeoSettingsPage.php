<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Magna\Seo\Integrations\SearchConsole;
use Magna\Seo\Settings\SeoSettings;
use UnitEnum;

/**
 * Admin screen for the site-wide SEO defaults held in {@see SeoSettings}. Per-page
 * overrides always win; these are the fallbacks.
 *
 * @property Schema $form Filament's default form, resolved via the HasForms magic accessor.
 */
class SeoSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Settings';

    // Explicit, like every other entry in this group: an unsorted item does not
    // respect its neighbours' order, which is what put Settings above Dashboard.
    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'SEO Settings';

    protected static ?string $slug = 'seo-settings';

    protected string $view = 'seo::filament.seo-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seo.settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = SeoSettings::get();

        $this->form->fill([
            'site_name' => $settings->site_name,
            'title_separator' => $settings->title_separator,
            'default_title_template' => $settings->default_title_template,
            'default_description_template' => $settings->default_description_template,
            'knowledge_graph_type' => $settings->knowledge_graph_type,
            'organization_name' => $settings->organization_name,
            'person_name' => $settings->person_name,
            'social_profiles' => $settings->social_profiles,
            'twitter_site' => $settings->twitter_site,
            'twitter_card_type' => $settings->twitter_card_type,
            'search_url_template' => $settings->search_url_template,
            'default_og_image' => $settings->default_og_image,
            'noindex_non_production' => $settings->noindex_non_production,
            'indexnow_key' => $settings->indexnow_key,
            'indexnow_auto_submit' => $settings->indexnow_auto_submit,
            'llms_txt_enabled' => $settings->llms_txt_enabled,
            'llms_txt_summary' => $settings->llms_txt_summary,
            'llms_full_enabled' => $settings->llms_full_enabled,
            'allow_ai_training' => $settings->allow_ai_training,
            'allow_ai_search' => $settings->allow_ai_search,
            'allow_ai_user_fetch' => $settings->allow_ai_user_fetch,
            'search_console_site' => $settings->search_console_site,
            'search_console_client_id' => $settings->search_console_client_id,
            'search_console_client_secret' => $settings->search_console_client_secret,
            'search_console_refresh_token' => $settings->search_console_refresh_token,
            'bing_webmaster_api_key' => $settings->bing_webmaster_api_key,
            'crux_api_key' => $settings->crux_api_key,
            'google_site_verification' => $settings->google_site_verification,
            'bing_site_verification' => $settings->bing_site_verification,
            'pinterest_site_verification' => $settings->pinterest_site_verification,
            'yandex_site_verification' => $settings->yandex_site_verification,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_name')
                            ->label('Site name')
                            ->helperText('Used by the %%sitename%% token. Falls back to the CMS site name.')
                            ->maxLength(120),
                        TextInput::make('title_separator')
                            ->label('Title separator')
                            ->helperText('Rendered for the %%sep%% token.')
                            ->maxLength(5),
                        TextInput::make('default_title_template')
                            ->label('Default title template')
                            ->helperText('Tokens: %%title%% %%sitename%% %%sep%% %%excerpt%%')
                            ->columnSpanFull()
                            ->maxLength(255),
                        TextInput::make('default_description_template')
                            ->label('Default description template')
                            ->helperText('%%excerpt%% falls back to the page body.')
                            ->columnSpanFull()
                            ->maxLength(255),
                        TextInput::make('search_url_template')
                            ->label('Site search URL')
                            ->helperText('e.g. https://example.com/search?q={search_term_string} — advertises a sitelinks search box. Leave blank to omit it.')
                            ->columnSpanFull()
                            ->maxLength(500),
                    ]),

                Section::make('Site identity')
                    ->description('Drives the Organization / Person node in structured data.')
                    ->columns(2)
                    ->schema([
                        Select::make('knowledge_graph_type')
                            ->label('This site represents')
                            ->options(['organization' => 'An organization', 'person' => 'A person'])
                            ->default('organization')
                            ->selectablePlaceholder(false),
                        TextInput::make('twitter_site')
                            ->label('Twitter/X @handle')
                            ->helperText('With or without the leading @.')
                            ->maxLength(50),
                        Select::make('twitter_card_type')
                            ->label('Default card type')
                            ->options([
                                '' => 'Automatic (large image when the page has one)',
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with large image',
                            ])
                            ->default('')
                            ->selectablePlaceholder(false),
                        TextInput::make('organization_name')
                            ->label('Organization name')
                            ->maxLength(150),
                        TextInput::make('person_name')
                            ->label('Person name')
                            ->maxLength(150),
                        TagsInput::make('social_profiles')
                            ->label('Social profile URLs')
                            ->helperText('Absolute URLs, emitted as schema sameAs.')
                            ->columnSpanFull(),
                        TextInput::make('default_og_image')
                            ->label('Default social image (media id)')
                            ->helperText('Media library id used when a page has no image of its own.')
                            ->columnSpanFull()
                            ->maxLength(64),
                    ]),

                Section::make('Indexing')
                    ->schema([
                        Toggle::make('noindex_non_production')
                            ->label('noindex on non-production environments')
                            ->helperText('Keeps staging out of search results.'),
                        TextInput::make('indexnow_key')
                            ->label('IndexNow key')
                            ->helperText('Served at /{key}.txt. Generated automatically on first submission if left blank.')
                            ->maxLength(128),
                        Toggle::make('indexnow_auto_submit')
                            ->label('Submit to IndexNow automatically')
                            ->helperText('Tells Bing, Yandex and others as soon as a page is published, updated or deleted. Changes are batched, not sent one per save.'),
                    ]),

                Section::make('AI platforms')
                    ->description('Two different things. The crawler switches decide whether AI platforms may use your site at all — that is the part with teeth. llms.txt is a curated map a model reads once it is already looking at your site; it does not affect indexing.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('allow_ai_search')
                            ->label('Allow AI search crawlers')
                            ->helperText('OAI-SearchBot, Claude-SearchBot, PerplexityBot. Turning this off removes your site from AI-assistant answers.'),
                        Toggle::make('allow_ai_training')
                            ->label('Allow crawling for model training')
                            ->helperText('GPTBot, ClaudeBot, Google-Extended, CCBot and others. Turning this off costs no search traffic.'),
                        Toggle::make('allow_ai_user_fetch')
                            ->label('Allow live fetches on a user request')
                            ->helperText('ChatGPT-User, Claude-User, Perplexity-User — someone pasted your URL and asked about it.'),
                        Toggle::make('llms_txt_enabled')
                            ->label('Publish /llms.txt'),
                        Toggle::make('llms_full_enabled')
                            ->label('Also publish /llms-full.txt')
                            ->helperText('Inlines the text of every page. Useful for a small documentation site, heavy for a large one.'),
                        Textarea::make('llms_txt_summary')
                            ->label('Site summary for llms.txt')
                            ->helperText('One paragraph describing what this site is. Emitted as the file\'s summary line.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(500),
                    ]),

                Section::make('Search Console')
                    ->description('Shows how each page is actually performing in Google search, next to the page itself. The secret and refresh token are encrypted at rest.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('search_console_site')
                            ->label('Property URL')
                            ->helperText('Exactly as it appears in Search Console, e.g. https://example.com/ or sc-domain:example.com')
                            ->maxLength(255),
                        TextInput::make('search_console_client_id')
                            ->label('OAuth client id')
                            ->maxLength(255),
                        TextInput::make('search_console_client_secret')
                            ->label('OAuth client secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        TextInput::make('search_console_refresh_token')
                            ->label('Refresh token')
                            ->password()
                            ->revealable()
                            ->maxLength(512),
                        TextInput::make('crux_api_key')
                            ->label('Chrome UX Report API key')
                            ->helperText('Optional. Enables Core Web Vitals field data — what real visitors experienced. Only available for pages with enough traffic.')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        TextInput::make('bing_webmaster_api_key')
                            ->label('Bing Webmaster API key')
                            ->helperText('Optional. IndexNow already covers Bing; use this only to have submissions show in your Webmaster account.')
                            ->password()
                            ->revealable()
                            ->columnSpanFull()
                            ->maxLength(255),
                    ]),

                Section::make('Search-engine verification')
                    ->description('Paste the verification token each service gives you. Emitted as a meta tag on every page.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('google_site_verification')->label('Google')->maxLength(255),
                        TextInput::make('bing_site_verification')->label('Bing')->maxLength(255),
                        TextInput::make('pinterest_site_verification')->label('Pinterest')->maxLength(255),
                        TextInput::make('yandex_site_verification')->label('Yandex')->maxLength(255),
                    ]),
            ]);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $string = static fn (string $key): string => trim((string) ($data[$key] ?? ''));

        $settings = SeoSettings::get();
        $settings->site_name = $string('site_name');
        $settings->title_separator = $string('title_separator');
        $settings->default_title_template = $string('default_title_template');
        $settings->default_description_template = $string('default_description_template');
        $settings->knowledge_graph_type = $string('knowledge_graph_type') === 'person' ? 'person' : 'organization';
        $settings->organization_name = $string('organization_name');
        $settings->person_name = $string('person_name');
        $settings->social_profiles = array_values(array_filter(
            (array) ($data['social_profiles'] ?? []),
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        ));
        $settings->twitter_site = $string('twitter_site');
        $settings->twitter_card_type = in_array($string('twitter_card_type'), ['summary', 'summary_large_image'], true)
            ? $string('twitter_card_type')
            : '';
        $settings->search_url_template = $string('search_url_template');
        $settings->default_og_image = $string('default_og_image');
        $settings->noindex_non_production = (bool) ($data['noindex_non_production'] ?? false);
        $settings->indexnow_key = $string('indexnow_key');
        $settings->indexnow_auto_submit = (bool) ($data['indexnow_auto_submit'] ?? false);
        $settings->llms_txt_enabled = (bool) ($data['llms_txt_enabled'] ?? false);
        $settings->llms_full_enabled = (bool) ($data['llms_full_enabled'] ?? false);
        $settings->llms_txt_summary = $string('llms_txt_summary');
        $settings->allow_ai_training = (bool) ($data['allow_ai_training'] ?? false);
        $settings->allow_ai_search = (bool) ($data['allow_ai_search'] ?? false);
        $settings->allow_ai_user_fetch = (bool) ($data['allow_ai_user_fetch'] ?? false);
        $settings->search_console_site = $string('search_console_site');
        $settings->search_console_client_id = $string('search_console_client_id');
        $settings->search_console_client_secret = $string('search_console_client_secret');
        $settings->search_console_refresh_token = $string('search_console_refresh_token');
        $settings->bing_webmaster_api_key = $string('bing_webmaster_api_key');
        $settings->crux_api_key = $string('crux_api_key');
        $settings->google_site_verification = $string('google_site_verification');
        $settings->bing_site_verification = $string('bing_site_verification');
        $settings->pinterest_site_verification = $string('pinterest_site_verification');
        $settings->yandex_site_verification = $string('yandex_site_verification');
        $settings->save();

        // Credentials may have changed; a cached token minted from the old ones
        // would fail every call until it expired.
        app(SearchConsole::class)->forgetToken();

        Notification::make()->title('SEO settings saved.')->success()->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),
        ];
    }
}
