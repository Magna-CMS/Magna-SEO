<?php

declare(strict_types=1);

namespace Magna\Seo\Filament\Resources\SeoRedirectResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Magna\Seo\Filament\Resources\SeoRedirectResource;

class ManageSeoRedirects extends ManageRecords
{
    protected static string $resource = SeoRedirectResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
