<?php

namespace App\Filament\Admin\Pages\Imports;

class ImportOwners extends ImportWizard
{
    protected static ?string $navigationLabel = 'Importar propietarios';

    protected static ?string $title = 'Importar propietarios';

    protected static ?int $navigationSort = 1;

    protected function importType(): string
    {
        return 'owners';
    }
}
