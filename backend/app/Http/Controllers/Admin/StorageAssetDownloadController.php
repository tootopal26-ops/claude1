<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorageAssetDownloadController extends Controller
{
    public function __invoke(StorageAsset $storageAsset): StreamedResponse
    {
        $staff = Auth::guard('staff')->user();
        $viewPermission = config("minimax.storage_buckets.{$storageAsset->bucket}.view_permission");

        abort_unless(
            $staff->hasPermission('storage.manage') || ($viewPermission !== null && $staff->hasPermission($viewPermission)),
            403,
        );

        return Storage::disk($storageAsset->disk)->download($storageAsset->path, $storageAsset->original_filename);
    }
}
