<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentStorageService;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFileController extends Controller
{
    public function __invoke(Document $document, string $action, DocumentStorageService $storage): StreamedResponse
    {
        abort_unless(auth()->check(), 401);
        Gate::authorize('view', $document);
        abort_unless(in_array($action, ['preview', 'download'], true), 404);
        abort_unless($document->disk === 'documents', 404);
        try {
            $storage->assertValidPath($document->file_path);
        } catch (\InvalidArgumentException) {
            abort(404);
        }
        $mime = $document->mime_type;
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(Storage::disk('documents')->exists($document->file_path), 404);

        return Storage::disk('documents')->response($document->file_path, $document->original_name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ], $action === 'preview' ? 'inline' : 'attachment');
    }
}
