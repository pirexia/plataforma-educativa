<?php

namespace App\Models;

use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-034 §2: concesión de un permiso a un rol del tenant. Sin política de
 * auditoría propia en 1.1 — en este paso solo la siembra
 * `tenant:provision-defaults`, no hay escritura de usuario que auditar
 * todavía (1.5 la traerá junto con el resolutor completo).
 *
 * @mixin IdeHelperPermissionRole
 */
class PermissionRole extends TenantModel
{
    protected $table = 'permission_role';

    protected $fillable = [
        'role_id',
        'permission_code',
        'effect',
        'scope',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * `permissions` es catálogo de plataforma (sin tenant_id, sin RLS):
     * la FK es `permission_code` -> `permissions.code`.
     *
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_code', 'code');
    }
}
