<?php

namespace App\Domain\Documents\Services;

use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentStorageService
{
    private const FORMATS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @return array{disk: string, file_path: string, stored_name: string, original_name: string, mime_type: string, file_size: int} */
    public function store(UploadedFile $file, DocumentType $type): array
    {
        $settings = $type->settings ?? [];
        $realPath = $file->getRealPath();
        $mime = $realPath === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = $settings['allowed_mimes'] ?? array_keys(self::FORMATS);
        $maxKilobytes = (int) ($settings['max_size_kb'] ?? 10240);
        $size = $file->getSize();

        if (! $file->isValid() || ! is_string($mime) || ! isset(self::FORMATS[$mime])
            || ! in_array($extension, self::FORMATS[$mime], true)
            || ! is_array($allowed) || ! in_array($mime, $allowed, true)
            || ! is_int($size) || $size < 1 || $maxKilobytes < 1 || $size > $maxKilobytes * 1024) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene un formato o tamaño permitido.']);
        }

        $storedName = Str::uuid().'.'.$extension;
        $path = 'communities/1/documents/'.$storedName;
        $disk = Storage::disk('documents');

        try {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('No se pudo leer el archivo.');
            }
            try {
                if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('No se pudo guardar el archivo.');
                }
            } finally {
                fclose($stream);
            }
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return [
            'disk' => 'documents', 'file_path' => $path, 'stored_name' => $storedName,
            'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
            'mime_type' => $mime, 'file_size' => $size,
        ];
    }

    public function delete(string $path): void
    {
        $this->assertValidPath($path);
        Storage::disk('documents')->delete($path);
    }

    public function assertValidPath(string $path): void
    {
        if (! preg_match('~\Acommunities/1/documents/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:pdf|jpg|jpeg|png|webp)\z~', $path)) {
            throw new \InvalidArgumentException('Ruta documental inválida.');
        }
    }
}
