<?php

namespace App\Filament\Admin\Pages\Importaciones;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\LoteImportService;
use App\Domain\Imports\Support\ImportTemplateOptions;
use App\Filament\Admin\Pages\Importaciones\Concerns\ManagesImport;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class ImportarLotes extends Page
{
    use ManagesImport;

    protected static string|UnitEnum|null $navigationGroup = 'Importaciones';

    protected static ?string $navigationLabel = 'Importar Lotes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.importaciones.importar-lotes';

    public static function templateHeaders(): array
    {
        return LoteImportService::HEADERS;
    }

    public static function templateExample(): array
    {
        return ['L-001', 'disponible', '5000', 'Etapa 1', '25000000', 'Lote en esquina'];
    }

    public static function templateFilename(): string
    {
        return 'plantilla-lotes';
    }

    protected function executeImport(ParsedFile $parsed): ImportResult
    {
        return app(LoteImportService::class)->import($parsed);
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateDropdowns(): array
    {
        return app(ImportTemplateOptions::class)->lotes();
    }
}
