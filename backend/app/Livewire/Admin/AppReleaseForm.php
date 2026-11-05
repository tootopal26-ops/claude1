<?php

namespace App\Livewire\Admin;

use App\Domain\Releases\AppReleaseService;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class AppReleaseForm extends Component
{
    use WithFileUploads;

    public string $versionName = '';

    public ?int $versionCode = null;

    public string $releaseNotes = '';

    public bool $makeCurrent = true;

    public ?TemporaryUploadedFile $file = null;

    public function mount(): void
    {
        $this->actor();
    }

    public function save(AppReleaseService $releases)
    {
        $staff = $this->actor();

        $maxKb = (int) config('minimax.storage_buckets.app_releases.max_size_kb', 262144);

        $this->validate([
            'versionName' => ['required', 'string', 'max:40'],
            'versionCode' => ['required', 'integer', 'min:1'],
            'releaseNotes' => ['nullable', 'string', 'max:5000'],
            'file' => ['required', 'file', 'max:'.$maxKb],
        ], attributes: ['versionName' => 'version name', 'versionCode' => 'version code', 'releaseNotes' => 'release notes']);

        $releases->publish(
            $this->file,
            'android',
            trim($this->versionName),
            $this->versionCode,
            trim($this->releaseNotes) ?: null,
            $this->makeCurrent,
            $staff,
        );

        session()->flash('status', 'Release uploaded.');

        return $this->redirectRoute(Portal::routeName('app-releases.index'), navigate: false);
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('app_releases.manage'), 403);

        return $staff;
    }

    public function render()
    {
        return view('livewire.admin.app-release-form');
    }
}
