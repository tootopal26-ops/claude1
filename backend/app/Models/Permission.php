<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'label', 'group'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<PermissionPreset, $this>
     */
    public function presets(): BelongsToMany
    {
        return $this->belongsToMany(PermissionPreset::class, 'permission_preset_permission');
    }
}
