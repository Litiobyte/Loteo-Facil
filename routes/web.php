<?php

use App\Http\Controllers\DocumentFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
    ]);
});

Route::get('/documents/{document}/{action}', DocumentFileController::class)
    ->whereIn('action', ['preview', 'download'])
    ->name('documents.file');
