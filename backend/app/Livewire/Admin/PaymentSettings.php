<?php

namespace App\Livewire\Admin;

use App\Models\PaymentProviderConfig;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class PaymentSettings extends Component
{
    public PaymentProviderConfig $config;

    public bool $isActive = true;

    public string $webhookSignatureHeader = '';

    public function mount(): void
    {
        $this->config = PaymentProviderConfig::firstOrCreate(
            ['provider' => 'alpha_upi'],
            ['is_active' => true, 'webhook_signature_header' => 'X-PayIndia-Signature'],
        );

        $this->isActive = $this->config->is_active;
        $this->webhookSignatureHeader = $this->config->webhook_signature_header;
    }

    public function save(): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('payments.manage'), 403);

        $this->validate([
            'webhookSignatureHeader' => ['required', 'string', 'max:255'],
        ]);

        $this->config->is_active = $this->isActive;
        $this->config->webhook_signature_header = $this->webhookSignatureHeader;
        $this->config->save();

        session()->flash('status', 'Payment settings saved.');
    }

    public function render()
    {
        return view('livewire.admin.payment-settings');
    }
}
