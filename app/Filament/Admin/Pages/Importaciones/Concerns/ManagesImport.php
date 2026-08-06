<?php

namespace App\Filament\Admin\Pages\Importaciones\Concerns;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Support\ImportFileReader;
use App\Domain\Imports\Support\TemplateGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

trait ManagesImport
{
    /**
     * @var array{created: int, updated: int, errors: list<array{row: int, field: ?string, message: string}>}
     */
    public array $resultData = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadTemplateAction(),
            $this->importAction(),
        ];
    }

    protected function importAction(): Action
    {
        return Action::make('importar')
            ->label('Importar archivo')
            ->icon('heroicon-o-arrow-up-tray')
            ->form([$this->fileUploadField()])
            ->action(function (array $data): void {
                $this->runImport($data['file']);
            });
    }

    protected function downloadTemplateAction(): Action
    {
        return Action::make('descargar_plantilla')
            ->label('Descargar plantilla')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->form([
                Select::make('formato')
                    ->label('Formato')
                    ->options(['csv' => 'CSV', 'xlsx' => 'Excel (XLSX)'])
                    ->default('csv')
                    ->required(),
            ])
            ->action(fn (array $data): StreamedResponse => $this->downloadTemplate($data['formato']));
    }

    protected function fileUploadField(): FileUpload
    {
        return FileUpload::make('file')
            ->label('Archivo a importar')
            ->disk('local')
            ->directory('imports')
            ->helperText('Formatos soportados: CSV, XLSX o XLS. Descarga la plantilla para conocer las columnas esperadas.')
            ->required();
    }

    protected function runImport(string $path): void
    {
        try {
            $parsed = app(ImportFileReader::class)->read(Storage::disk('local')->path($path));

            Storage::disk('local')->delete($path);

            $result = $this->executeImport($parsed);

            $this->resultData = [
                'created' => $result->created,
                'updated' => $result->updated,
                'errors' => $result->errors,
            ];

            if ($result->hasErrors()) {
                Notification::make()
                    ->warning()
                    ->title('Importación finalizada con errores')
                    ->body(sprintf(
                        'Se procesaron %d filas correctamente y %d presentaron errores.',
                        $result->successful(),
                        $result->errorCount(),
                    ))
                    ->send();
            } else {
                Notification::make()
                    ->success()
                    ->title('Importación completada')
                    ->body(sprintf('Se procesaron %d filas correctamente.', $result->successful()))
                    ->send();
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            $this->resultData = [];

            Notification::make()
                ->danger()
                ->title('No se pudo importar el archivo')
                ->body($exception->getMessage())
                ->send();
        }
    }

    abstract protected function executeImport(ParsedFile $parsed): ImportResult;

    protected function downloadTemplate(string $format): StreamedResponse
    {
        $filename = static::templateFilename();

        return $format === 'xlsx'
            ? TemplateGenerator::xlsx(static::templateHeaders(), static::templateExample(), $filename)
            : TemplateGenerator::csv(static::templateHeaders(), static::templateExample(), $filename);
    }

    /**
     * @return list<string>
     */
    abstract public static function templateHeaders(): array;

    /**
     * @return list<string|int|float|null>
     */
    abstract public static function templateExample(): array;

    abstract public static function templateFilename(): string;
}
