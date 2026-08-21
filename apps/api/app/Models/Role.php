<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-034 §2: esquema completo desde 0.8, resolutor de permisos en 1.5.
 * mfa_required/special_data_access existen desde ahora aunque nadie los
 * lea todavía (RPERM-014, RPERM-015).
 *
 * ADR-035 §8: Full — `name` es contenido del centro, no dato personal.
 *
 * @mixin IdeHelperRole
 */
class Role extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

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

    /**
     * @return HasMany<PermissionRole, $this>
     */
    public function permissionGrants(): HasMany
    {
        return $this->hasMany(PermissionRole::class);
    }
}
