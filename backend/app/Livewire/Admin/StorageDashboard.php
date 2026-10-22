<?php

namespace App\Livewire\Admin;

use App\Domain\Storage\StorageService;
use App\Models\StorageAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StorageDashboard extends Component
{
    public string $bucketFilter = '';

    public ?int $retainingAssetId = null;

    public string $retainReason = '';

    public ?string $actionError = null;

    public function retain(int $assetId, StorageService $storage): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('storage.manage'), 403);

        $this->actionError = null;
        $asset = StorageAsset::findOrFail($assetId);

        try {
            $storage->retain($asset, $this->retainReason, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->actionError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->retainingAssetId = null;
        $this->retainReason = '';
    }

    public function unretain(int $assetId, StorageService $storage): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('storage.manage'), 403);

        $storage->unretain(StorageAsset::findOrFail($assetId), Auth::guard('staff')->user());
    }

    public function delete(int $assetId, StorageService $storage): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('storage.manage'), 403);

        $storage->delete(StorageAsset::findOrFail($assetId), Auth::guard('staff')->user());
    }

    public function render(StorageService $storage)
    {
        $usage = $storage->usageByBucket();

        $assets = StorageAsset::query()
            ->when($this->bucketFilter !== '', fn ($query) => $query->where('bucket', $this->bucketFilter))
            ->with('uploadedByStaff')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.storage-dashboard', [
            'usage' => $usage,
            'assets' => $assets,
            'buckets' => config('minimax.storage_buckets'),
        ]);
    }
}
