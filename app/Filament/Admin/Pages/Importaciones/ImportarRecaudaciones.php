<?php

namespace App\Filament\Admin\Pages\Importaciones;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\CollectionImportService;
use App\Filament\Admin\Pages\Importaciones\Concerns\ManagesImport;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ImportarRecaudaciones extends Page
{
    use ManagesImport;

    protected static string|UnitEnum|null $navigationGroup = 'Importaciones';

    protected static ?string $navigationLabel = 'Importar Recaudaciones';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.admin.importaciones.importar-recaudaciones';

    public static function templateHeaders(): array
    {
        return CollectionImportService::HEADERS;
    }

    public static function templateExample(): array
    {
        return ['11111111-1', '50000', '2026-08-01', 'transferencia', 'REC-2026-001', 'Pago de cuota'];
    }

    public static function templateFilename(): string
    {
        return 'plantilla-recaudaciones';
    }

    protected function executeImport(ParsedFile $parsed): ImportResult
    {
        return app(CollectionImportService::class)->import($parsed, Auth::id());
    }
}
