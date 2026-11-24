<?php

namespace App\Console\Commands;

use App\Domain\Documents\Services\DocumentStorageService;
use App\Domain\Documents\Services\PrivateStorageService;
use App\Models\CommunitySetting;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CopyPrivateFiles extends Command
{
    protected $signature = 'private-files:copy {--target=local : Destino: local o r2} {--execute : Copiar; por defecto solo simula}';

    protected $description = 'Copia y verifica archivos históricos sin borrar originales ni cambiar referencias';

    public function handle(PrivateStorageService $storage, DocumentStorageService $documents): int
    {
        $target = match ($this->option('target')) {
            'local' => 'documents-local',
            'r2' => 'r2',
            default => null,
        };
        if ($target === null) {
            $this->error('Destino inválido.');

            return self::FAILURE;
        }

        $errors = 0;
        foreach (Document::withTrashed()->cursor() as $document) {
            try {
                if ($document->disk !== 'documents') {
                    throw new \RuntimeException('Disco documental desconocido.');
                }
                $documents->assertValidPath($document->file_path);
                $this->copy($storage, 'documents-local', $target, $document->file_path);
            } catch (Throwable $exception) {
                $errors++;
                $this->error("Documento {$document->id}: {$exception->getMessage()}");
            }
        }

        $path = CommunitySetting::query()->whereKey(1)->value('logo_path');
        if ($path !== null) {
            try {
                if (! is_string($path) || ! preg_match('/\Acommunity-logos\/[a-zA-Z0-9]{40}\.(png|jpg|jpeg|webp)\z/', $path)) {
                    throw new \RuntimeException('Ruta de logotipo inválida.');
                }
                $source = Storage::disk('public')->exists($path) ? 'public' : 'documents-local';
                $this->copy($storage, $source, $target, $path);
            } catch (Throwable $exception) {
                $errors++;
                $this->error("Logotipo: {$exception->getMessage()}");
            }
        }

        $this->info($this->option('execute') ? 'Copia finalizada; los originales no se han eliminado.' : 'Simulación finalizada; no se modificaron archivos.');

        return $errors ? self::FAILURE : self::SUCCESS;
    }

    private function copy(PrivateStorageService $storage, string $source, string $target, string $path): void
    {
        if (! Storage::disk($source)->exists($path)) {
            throw new \RuntimeException("Falta el origen: {$path}.");
        }
        $this->line("{$source} -> {$target}: {$path}");
        if ($this->option('execute')) {
            $storage->copyVerified($source, $target, $path);
        }
    }
}
