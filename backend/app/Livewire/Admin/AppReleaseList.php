<?php

namespace App\Livewire\Admin;

use App\Domain\Releases\AppReleaseService;
use App\Models\AppRelease;
use App\Models\AppReleaseDownload;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AppReleaseList extends Component
{
    use WithPagination;

    public function setCurrent(int $releaseId, AppReleaseService $releases): void
    {
        $this->actor();

        $releases->setCurrent(AppRelease::findOrFail($releaseId), $this->actor());

        session()->flash('status', 'That release is now the one players download.');
    }

    public function delete(int $releaseId, AppReleaseService $releases): void
    {
        $releases->delete(AppRelease::findOrFail($releaseId), $this->actor());

        session()->flash('status', 'The release was deleted.');
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('app_releases.manage'), 403);

        return $staff;
    }

    public function render()
    {
        $this->actor();

        $since = fn (int $days) => AppReleaseDownload::where('downloaded_at', '>=', now()->subDays($days))->count();

        return view('livewire.admin.app-release-list', [
            'releases' => AppRelease::query()->platform('android')->orderByDesc('version_code')->paginate(15),
            'downloadsTotal' => AppReleaseDownload::count(),
            'downloads7d' => $since(7),
            'downloads30d' => $since(30),
        ]);
    }
}
