<?php

namespace App\Models;

use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['display_name', 'username', 'password', 'permission_preset_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class StaffUser extends Authenticatable
{
    /** @use HasFactory<StaffUserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<PermissionPreset, $this>
     */
    public function permissionPreset(): BelongsTo
    {
        return $this->belongsTo(PermissionPreset::class);
    }

    public function hasPermission(string $key): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->permissionPreset?->permissions->contains('key', $key) ?? false;
    }
}
