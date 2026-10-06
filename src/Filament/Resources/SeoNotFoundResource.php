<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Magna\Seo\Filament\Resources\SeoNotFoundResource\Pages;
use Magna\Seo\Models\SeoNotFound;
use Magna\Seo\Models\SeoRedirect;
use Magna\Seo\Redirects\MatchType;

/**
 * The 404 log, sorted by how often each dead URL is actually hit — which is the
 * only ordering that matters, since fixing the top few recovers most of the lost
 * traffic. Each row carries a one-click "redirect this" action so going from
 * evidence to fix never involves retyping a path.
 *
 * Rows are created by the middleware only; there is no create screen.
 */
class SeoNotFoundResource extends Resource
{
    protected static ?string $model = SeoNotFound::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Not found (404)';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'path';

    public static function getModelLabel(): string
    {
        return '404';
    }

    public static function getPluralModelLabel(): string
    {
        return '404s';
    }

    public static function canViewAny(): bool
    {
        return static::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
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
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')->searchable()->wrap(),
                TextColumn::make('hits')->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable()->label('Last seen'),
                TextColumn::make('last_referrer')->label('Referrer')->wrap()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('hits', 'desc')
            ->recordActions([
                Action::make('redirect')
                    ->label('Redirect this')
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->visible(fn (): bool => static::allowed())
                    ->schema([
                        TextInput::make('target')
                            ->label('To')
                            ->required()
                            ->maxLength(2048)
                            ->helperText('A path such as /new-page, or a full https:// URL.'),
                        Select::make('status_code')
                            ->label('Status')
                            ->options([
                                301 => '301 — moved permanently',
                                302 => '302 — temporary',
                                410 => '410 — gone',
                            ])
                            ->default(301)
                            ->required(),
                    ])
                    ->action(function (SeoNotFound $record, array $data): void {
                        $status = (int) ($data['status_code'] ?? 301);

                        SeoRedirect::query()->create([
                            'source_path' => $record->path,
                            'match_type' => MatchType::Exact->value,
                            'target' => $status === 410 ? null : (string) ($data['target'] ?? ''),
                            'status_code' => $status,
                            'preserve_query' => true,
                            'is_active' => true,
                            'notes' => 'Created from the 404 log.',
                        ]);

                        // The path is handled now, so it stops competing for
                        // attention at the top of the list.
                        $record->delete();

                        Notification::make()
                            ->title('Redirect created.')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoNotFounds::route('/'),
        ];
    }
}
