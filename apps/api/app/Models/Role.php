<?php

namespace App\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ADR-034 §2: esquema completo desde 0.8, resolutor de permisos en 1.5.
 * mfa_required/special_data_access existen desde ahora aunque nadie los
 * lea todavía (RPERM-014, RPERM-015).
 */
class Role extends TenantModel
{
    use HasPublicId;

    protected $fillable = [
        'code',
        'name_key',
        'name',
        'is_system',
        'mfa_required',
        'special_data_access',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'mfa_required' => 'boolean',
        'special_data_access' => 'boolean',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }
}
