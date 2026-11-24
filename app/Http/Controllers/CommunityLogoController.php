<?php

namespace App\Http\Controllers;

use App\Models\CommunitySetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunityLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'super_admin', 'propietario']), 403);
        $path = CommunitySetting::query()->whereKey(1)->value('logo_path');
        abort_unless(is_string($path) && preg_match('/\Acommunity-logos\/[a-zA-Z0-9]{40}\.(png|jpg|jpeg|webp)\z/', $path), 404);
        $disk = Storage::disk('documents');
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
