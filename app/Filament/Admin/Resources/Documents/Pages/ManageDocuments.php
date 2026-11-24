<?php

namespace App\Filament\Admin\Resources\Documents\Pages;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\CommunitySetting;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return DocumentResource::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('community')->label('Documentos comunitarios')
                ->modalSubmitAction(false)
                ->modalContent(fn () => view('filament.documents.community-upload', ['community' => CommunitySetting::current()])),
        ];
    }
}
