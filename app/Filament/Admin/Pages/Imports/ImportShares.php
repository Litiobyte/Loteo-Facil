<?php

namespace App\Filament\Admin\Pages\Imports;

use App\Models\CommunitySetting;

class ImportShares extends ImportWizard
{
    protected static ?string $navigationLabel = 'Importar acciones';

    protected static ?string $title = 'Importar saldos de acciones';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && CommunitySetting::current()->usesShares();
    }

    protected function importType(): string
    {
        return 'shares';
    }
}
