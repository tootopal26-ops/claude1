<?php

namespace App\Models;

use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Bump this so every currently-logged-in session for this account is
     * forced to re-authenticate on its next request (see
     * EnsureStaffSecurityVersionIsCurrent). Call after changing anything
     * that affects what this account can do or whether it can log in at
     * all: permission preset, super-admin flag, active status, password.
     */
    public function invalidateActiveSessions(): void
    {
        $this->increment('security_version');
    }

    /**
     * @return HasMany<StaffSalaryRule, $this>
     */
    public function salaryRules(): HasMany
    {
        return $this->hasMany(StaffSalaryRule::class);
    }

    /**
     * @return HasMany<StaffSalaryEvent, $this>
     */
    public function salaryEvents(): HasMany
    {
        return $this->hasMany(StaffSalaryEvent::class);
    }

    /**
     * @return HasMany<StaffSalarySettlement, $this>
     */
    public function salarySettlements(): HasMany
    {
        return $this->hasMany(StaffSalarySettlement::class);
    }
}
