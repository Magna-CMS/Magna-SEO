<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Filament\Resources\SeoRedirectResource\Pages;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\MatchType;
use UnitEnum;

/**
 * Redirect management. The form is deliberately opinionated about the three
 * mistakes that cost the most: a 302 where a 301 was meant, a target that is not
 * a URL, and a regex that does not compile.
 */
class SeoRedirectResource extends Resource
{
    protected static ?string $model = SeoRedirect::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-right';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Redirects';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'source_path';

    public static function getModelLabel(): string
    {
        return 'redirect';
    }

    public static function canViewAny(): bool
    {
        return static::allowed();
    }

    public static function canCreate(): bool
    {
        return static::allowed();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed();
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowed();
    }

    protected static function allowed(): bool
    {
        return auth()->user()?->can('seo.redirects.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('match_type')
                ->label('Match')
                ->options([
                    MatchType::Exact->value => 'Exact path',
                    MatchType::Regex->value => 'Regular expression',
                ])
                ->default(MatchType::Exact->value)
                ->live()
                ->required(),

            TextInput::make('source_path')
                ->label('From')
                ->required()
                ->maxLength(2048)
                ->helperText(fn (Get $get): string => $get('match_type') === MatchType::Regex->value
                    ? 'A pattern matched against the path, e.g. ^/old/(.*)$ — capture groups are available in the target as $1.'
                    : 'The old path, e.g. /old-page. A query string may be included to target one variant only.')
                ->rules([
                    fn (Get $get): callable => function (string $attribute, mixed $value, callable $fail) use ($get): void {
                        if ($get('match_type') !== MatchType::Regex->value || ! is_string($value)) {
                            return;
                        }

                        // Compile the author's pattern now, so a broken one is a
                        // form error instead of a rule that silently never fires.
                        if (@preg_match('#'.str_replace('#', '\#', $value).'#', '') === false) {
                            $fail('That is not a valid regular expression.');
                        }
                    },
                ]),

            Select::make('status_code')
                ->label('Status')
                ->options([
                    301 => '301 — moved permanently (use this)',
                    308 => '308 — permanent, keeps the request method',
                    302 => '302 — temporary',
                    307 => '307 — temporary, keeps the request method',
                    410 => '410 — gone, no destination',
                ])
                ->default(301)
                ->live()
                ->required(),

            TextInput::make('target')
                ->label('To')
                ->maxLength(2048)
                ->required(fn (Get $get): bool => (int) $get('status_code') !== 410)
                ->visible(fn (Get $get): bool => (int) $get('status_code') !== 410)
                ->helperText('A path such as /new-page, or a full https:// URL.')
                ->rules([
                    static function (string $attribute, mixed $value, callable $fail): void {
                        if (! is_string($value) || $value === '') {
                            return;
                        }

                        $relative = str_starts_with($value, '/') && ! str_starts_with($value, '//');
                        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                        if (! $relative && ! in_array($scheme, ['http', 'https'], true)) {
                            $fail('The destination must be a path starting with / or an http(s) URL.');
                        }
                    },
                ]),

            Toggle::make('preserve_query')
                ->label('Carry the query string over')
                ->default(true),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),

            Textarea::make('notes')
                ->maxLength(500)
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_path')->label('From')->searchable()->wrap(),
                TextColumn::make('target')->label('To')->searchable()->wrap()->placeholder('—'),
                TextColumn::make('status_code')->label('Status')->badge()
                    ->color(fn (int $state): string => match ($state) {
                        301, 308 => 'success',
                        410 => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('match_type')->label('Match')->badge()->color('gray'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('hits')->sortable()->label('Hits'),
                TextColumn::make('last_hit_at')->dateTime()->sortable()->placeholder('never')->label('Last hit'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSeoRedirects::route('/'),
        ];
    }
}
