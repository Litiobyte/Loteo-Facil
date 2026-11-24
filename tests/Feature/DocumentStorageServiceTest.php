<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentStorageService;
use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentStorageServiceTest extends TestCase
{
    public function test_it_stores_real_pdf_privately_and_deletes_only_safe_paths(): void
    {
        Storage::fake('documents');
        $service = app(DocumentStorageService::class);
        $file = UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nexample");
        $stored = $service->store($file, new DocumentType);
        Storage::disk('documents')->assertExists($stored['file_path']);
        $this->assertStringStartsWith('communities/1/documents/', $stored['file_path']);
        $service->delete($stored['file_path']);
        Storage::disk('documents')->assertMissing($stored['file_path']);
    }

    public function test_it_rejects_file_with_spoofed_extension(): void
    {
        Storage::fake('documents');
        $this->expectException(ValidationException::class);
        app(DocumentStorageService::class)->store(
            UploadedFile::fake()->createWithContent('fake.pdf', 'plain text'),
            new DocumentType,
        );
    }

    public function test_it_rejects_files_above_type_size_limit(): void
    {
        Storage::fake('documents');
        $type = new DocumentType(['settings' => ['max_size_kb' => 1]]);
        $this->expectException(ValidationException::class);
        app(DocumentStorageService::class)->store(
            UploadedFile::fake()->createWithContent('large.pdf', "%PDF-1.4\n".str_repeat('a', 2048)),
            $type,
        );
    }

    public function test_it_rejects_traversal_on_delete(): void
    {
        Storage::fake('documents');
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentStorageService::class)->delete('communities/1/documents/../../secret.pdf');
    }
}
