<?php

namespace App\Filament\Admin\Resources\CollectionResource\Pages;

use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Admin\Resources\CollectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCollection extends CreateRecord
{
    protected static string $resource = CollectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = CollectionStatus::PendingApplication;
        $data['applied_amount'] = 0;
        $data['unapplied_amount'] = (float) ($data['amount'] ?? 0);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
