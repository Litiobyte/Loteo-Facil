<?php

namespace App\Filament\Admin\Resources\DocumentTypes\Pages;

use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentTypes extends ManageRecords
{
    protected static string $resource = DocumentTypeResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return DocumentTypeResource::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
