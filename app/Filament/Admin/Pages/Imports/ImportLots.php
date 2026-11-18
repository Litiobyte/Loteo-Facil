<?php

namespace App\Filament\Admin\Pages\Imports;

class ImportLots extends ImportWizard
{
    protected static ?string $navigationLabel = 'Importar lotes';

    protected static ?string $title = 'Importar lotes';

    protected static ?int $navigationSort = 2;

    protected function importType(): string
    {
        return 'lots';
    }
}
