<?php

namespace App\Http\Controllers;

use App\Domain\Releases\AppReleaseService;
use App\Models\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one public, unauthenticated way to get the app: no Play Store listing exists for this
 * project (see docs/mobile-release.md), so this is the real distribution channel — the link
 * Admin puts on the website/landing page/QR code. Always serves whichever release Admin has
 * marked current; there is deliberately no way to request an older build from here.
 */
class AppReleaseDownloadController extends Controller
{
    public function __invoke(Request $request, AppReleaseService $releases, string $platform = 'android'): StreamedResponse
    {
        $release = AppRelease::query()->platform($platform)->current()->first();

        abort_if($release === null, 404, 'No release is currently available for download.');

        $asset = $release->fileAsset;
        abort_if($asset === null, 404);

        $releases->recordDownload($release, $request->ip(), $request->userAgent());

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_filename);
    }
}
