<?php

namespace App\Livewire\Admin;

use App\Domain\Settings\PlatformSettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin > Customer Support: the link the app's Profile > Customer Support row opens.
 * The value lives in platform settings (validated and audit-logged by
 * PlatformSettingsService); the app reads it from GET /api/v1/app-config.
 */
#[Layout('components.layouts.admin')]
class CustomerSupportSettings extends Component
{
    public string $supportUrl = '';

    public ?string $savedUrl = null;

    public function mount(PlatformSettingsService $settings): void
    {
        $this->savedUrl = $settings->get('support_url');
        $this->supportUrl = (string) $this->savedUrl;
    }

    public function save(PlatformSettingsService $settings): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('settings.manage'), 403);

        $this->resetErrorBag();

        try {
            $settings->set('support_url', $this->supportUrl, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->addError('supportUrl', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->savedUrl = $settings->get('support_url');
        $this->supportUrl = (string) $this->savedUrl;

        session()->flash('status', $this->savedUrl ? 'Support link saved. The app uses it from now on.' : 'Support link removed. The app now says support is not available yet.');
    }

    public function render()
    {
        return view('livewire.admin.customer-support-settings');
    }
}
