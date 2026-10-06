<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Magna\Seo\Dashboard\ContentOverview;
use UnitEnum;

/**
 * Every indexable page and its SEO state, with the two fields most often missing
 * editable in place.
 *
 * Fixing two hundred absent meta descriptions by opening two hundred editors is
 * not a workflow anybody completes, so the fix happens on the list.
 */
class SeoContentPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'SEO Content';

    protected static ?string $slug = 'seo-content';

    protected string $view = 'seo::filament.seo-content';

    /**
     * Bound to the query string so other screens can deep-link here already
     * filtered — the dashboard's "Fix these" buttons rely on it, and a filter
     * that survives a reload is worth having regardless.
     */
    #[Url]
    public string $filter = ContentOverview::FILTER_ALL;

    #[Url]
    public string $search = '';

    /** @var array<string, array{title?: string, description?: string}> */
    public array $edits = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seo.meta.edit') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        return ContentOverview::filters();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return app(ContentOverview::class)->rows($this->filter, trim($this->search));
    }

    /**
     * Persist one row's title and description.
     *
     * Only rows backed by a model can be saved: a source with no Eloquent model
     * has nowhere to attach an override, and the view hides the fields for those
     * rather than offering an edit that would silently do nothing.
     */
    public function saveRow(string $modelType, string $modelId): void
    {
        $edit = $this->edits[$modelId] ?? null;

        if ($edit === null) {
            return;
        }

        app(ContentOverview::class)->save(
            $modelType,
            $modelId,
            $edit['title'] ?? null,
            $edit['description'] ?? null,
        );

        Notification::make()->title('Saved.')->success()->send();
    }
}
