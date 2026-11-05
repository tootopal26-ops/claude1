<?php

use App\Http\Controllers\AppReleaseDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The real distribution channel for the Android app — no Play Store listing exists for this
// project (see docs/mobile-release.md). Always serves whichever release Admin > App Releases
// has marked current; public and unauthenticated by design.
Route::get('/download/{platform?}', AppReleaseDownloadController::class)
    ->name('app-releases.public-download')
    ->where('platform', 'android');
