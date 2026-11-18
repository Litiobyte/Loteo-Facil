<?php

namespace App\Filament\Admin\Resources\CommunitySettingResource\Pages;

use App\Filament\Admin\Resources\CommunitySettingResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunitySettings extends ManageRecords
{
    protected static string $resource = CommunitySettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
