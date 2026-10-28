<?php

namespace App\Livewire\Admin\Ads\Concerns;

use App\Domain\Settings\PlatformSettingsService;
use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A settings form over a fixed list of platform-settings keys. Values are validated and
 * written only by PlatformSettingsService (including rules across settings, such as the
 * revenue shares adding up to 100%), all together or not at all, and every change is
 * audit-logged with its previous and new value.
 */
trait EditsSettings
{
    /** @var array<string, mixed> */
    public array $values = [];

    /**
     * @return list<string>
     */
    abstract protected function settingKeys(): array;

    protected function loadSettings(PlatformSettingsService $settings): void
    {
        foreach ($this->settingKeys() as $key) {
            $value = $settings->get($key);
            $this->values[$key] = PlatformSettingsService::CATALOG[$key]['type'] === 'bool' ? (bool) $value : (string) $value;
        }
    }

    protected function saveSettings(PlatformSettingsService $settings, string $flash): void
    {
        $this->ensureCanManage();
        $this->resetErrorBag();

        try {
            $changed = $settings->setMany(array_intersect_key($this->values, array_flip($this->settingKeys())), $this->actor());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(isset(PlatformSettingsService::CATALOG[$key]) ? "values.{$key}" : 'values', $messages[0]);
            }

            return;
        }

        $this->loadSettings($settings);
        session()->flash('status', $changed === [] ? 'Nothing changed.' : $flash);
    }

    /**
     * The recorded changes to this page's settings, newest first.
     *
     * @return Collection<int, AuditLog>
     */
    protected function settingsHistory(int $limit = 15): Collection
    {
        return AuditLog::query()
            ->with('actor')
            ->where('action', 'platform_settings.updated')
            ->whereIn('metadata->key', $this->settingKeys())
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
