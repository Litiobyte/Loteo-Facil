<?php

namespace App\Filament\Admin\Resources\PartnerChargeResource\Pages;

use App\Filament\Admin\Resources\PartnerChargeResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerCharge extends ViewRecord
{
    protected static string $resource = PartnerChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
