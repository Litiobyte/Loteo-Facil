<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'community' => ['nullable', 'string', 'max:190'],
        ]);

        Mail::send(new ContactInquiry(
            name: $data['name'],
            email: $data['email'],
            community: $data['community'] ?? '',
        ));

        return response()->json(['status' => 'ok']);
    }
}
