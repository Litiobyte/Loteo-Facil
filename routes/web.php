<?php

use App\Http\Controllers\CommunityLogoController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DocumentFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/contacto', ContactController::class)
    ->middleware('throttle:5,1')
    ->name('contact.submit');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
    ]);
});

Route::get('/documents/{document}/{action}', DocumentFileController::class)
    ->whereIn('action', ['preview', 'download'])
    ->name('documents.file');

Route::get('/community/logo', CommunityLogoController::class)->middleware('auth')->name('community.logo');
