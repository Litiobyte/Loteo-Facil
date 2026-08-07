<?php

namespace App\Filament\Admin\Pages\Importaciones;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\PropietarioImportService;
use App\Domain\Imports\Support\ImportTemplateOptions;
use App\Filament\Admin\Pages\Importaciones\Concerns\ManagesImport;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class ImportarPropietarios extends Page
{
    use ManagesImport;

    protected static string|UnitEnum|null $navigationGroup = 'Importaciones';

    protected static ?string $navigationLabel = 'Importar Propietarios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.importaciones.importar-propietarios';

    public static function templateHeaders(): array
    {
        return PropietarioImportService::HEADERS;
    }

    public static function templateExample(): array
    {
        return ['11111111-1', 'Juan', 'Perez', 'juan.perez@correo.cl', '+56 9 1234 5678', 'Av. Siempre Viva 123', 'Metropolitana de Santiago', 'Santiago', 'Chilena', 'Ingeniero', 'Casado'];
    }

    public static function templateFilename(): string
    {
        return 'plantilla-propietarios';
    }

    protected function executeImport(ParsedFile $parsed): ImportResult
    {
        return app(PropietarioImportService::class)->import($parsed);
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateDropdowns(): array
    {
        return app(ImportTemplateOptions::class)->propietarios();
    }
}
