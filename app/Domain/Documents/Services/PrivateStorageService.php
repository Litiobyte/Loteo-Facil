<?php

namespace App\Domain\Documents\Services;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateStorageService
{
    public function putStream(string $path, mixed $stream, string $disk = 'documents'): void
    {
        $storage = Storage::disk($disk);

        if ($storage instanceof AwsS3V3Adapter) {
            $contentType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                default => 'application/octet-stream',
            };
            $storage->getClient()->putObject([
                'Bucket' => config("filesystems.disks.{$disk}.bucket"),
                'Key' => $path,
                'Body' => $stream,
                'ContentType' => $contentType,
            ]);

            return;
        }

        if (! $storage->put($path, $stream)) {
            throw new RuntimeException('No se pudo guardar el archivo privado.');
        }
    }

    public function copyVerified(string $sourceDisk, string $targetDisk, string $path): void
    {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);
        $sourceStream = $source->readStream($path);
        if (! is_resource($sourceStream)) {
            throw new RuntimeException("No se pudo leer {$path}.");
        }

        try {
            $sourceHash = hash_init('sha256');
            hash_update_stream($sourceHash, $sourceStream);
            $expected = hash_final($sourceHash);
            rewind($sourceStream);
            if (! $target->exists($path)) {
                $this->putStream($path, $sourceStream, $targetDisk);
            }
        } finally {
            fclose($sourceStream);
        }

        $targetStream = $target->readStream($path);
        if (! is_resource($targetStream)) {
            throw new RuntimeException("No se pudo verificar {$path}.");
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $targetStream);
            if (! hash_equals($expected, hash_final($hash))) {
                throw new RuntimeException("Conflicto o copia incompleta: {$path}.");
            }
        } finally {
            fclose($targetStream);
        }
    }
}
