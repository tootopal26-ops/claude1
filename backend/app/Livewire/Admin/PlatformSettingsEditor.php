<?php

namespace App\Livewire\Admin;

use App\Domain\Settings\PlatformSettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class PlatformSettingsEditor extends Component
{
    /** @var array<string, string> */
    public array $values = [];

    public ?string $formError = null;

    public function mount(PlatformSettingsService $settings): void
    {
        foreach ($settings->all() as $key => $value) {
            $this->values[$key] = PlatformSettingsService::CATALOG[$key]['type'] === 'string_list'
                ? implode("\n", (array) $value)
                : (string) $value;
        }
    }

    public function save(PlatformSettingsService $settings): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('settings.manage'), 403);

        $this->formError = null;

        foreach (PlatformSettingsService::CATALOG as $key => $spec) {
            $raw = trim($this->values[$key] ?? '');

            $value = match ($spec['type']) {
                'int', 'decimal' => $raw,
                'string_list' => collect(preg_split('/\r?\n/', $raw))->map(fn ($line) => trim($line))->filter()->values()->all(),
            };

            if (in_array($spec['type'], ['int', 'decimal'], true) && (! is_numeric($value) || (float) $value < 0)) {
                $this->formError = "\"{$spec['label']}\" must be a non-negative number.";

                return;
            }

            $value = match ($spec['type']) {
                'int' => (int) $value,
                'decimal' => number_format((float) $value, 2, '.', ''),
                default => $value,
            };

            try {
                $settings->set($key, $value, Auth::guard('staff')->user());
            } catch (ValidationException $exception) {
                $this->formError = collect($exception->errors())->flatten()->first();

                return;
            }
        }

        session()->flash('status', 'Platform settings saved.');
    }

    public function render()
    {
        return view('livewire.admin.platform-settings-editor', [
            'catalog' => PlatformSettingsService::CATALOG,
        ]);
    }
}
