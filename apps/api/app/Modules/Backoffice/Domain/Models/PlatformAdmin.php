<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Support\Database\HasPublicId;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * REQ-BO-007, datos.md §2.1. La identidad de plataforma: RN-BO-01, ningún
 * `platform_admin` tiene tenant — ninguna columna es `tenant_id`, ni
 * nula, y ninguna consulta pasa por el scope global de tenant.
 *
 * Conexión de plataforma directa (`pgsql_platform`), igual que `Tenant`:
 * este módulo no necesita `TenantContext::runAsPlatform()` para leer o
 * escribir sus propias tablas (ninguna es de tenant); esa primitiva
 * sirve para cuando el backoffice toca una tabla de TENANT (p. ej.
 * `module_subscriptions`, 1.6c).
 *
 * No implementa `Auditable`/`RecordsAuditTrail` (ADR-035): esa maquinaria
 * escribe en `audit_logs`, que es tabla de tenant. El rastro de toda
 * escritura sobre este modelo lo deja explícitamente el servicio que
 * escribe, en `admin_action_logs` (RN-BO-29 a RN-BO-32).
 *
 * No tiene `person_id` (Person es de tenant, ADR-034 §1) ni
 * `mfa_required` (obligatorio sin excepción, datos.md §2.1).
 *
 * @mixin IdeHelperPlatformAdmin
 */
class PlatformAdmin extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'email',
        'name',
        'password',
        'status',
        'locale',
        'last_login_at',
        'password_changed_at',
        'mfa_enrolled_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformAdminStatus::class,
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_enrolled_at' => 'datetime',
        ];
    }

    public function hasMfaEnrolled(): bool
    {
        return $this->mfa_enrolled_at !== null;
    }

    /**
     * @return HasMany<PlatformAdminRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(PlatformAdminRole::class);
    }

    /**
     * @return HasMany<PlatformAdminMfaFactor, $this>
     */
    public function mfaFactors(): HasMany
    {
        return $this->hasMany(PlatformAdminMfaFactor::class);
    }

    /**
     * Los códigos de rol vivos de este administrador (datos.md §2.2).
     *
     * @return list<PlatformRole>
     */
    public function roles(): array
    {
        return $this->roleAssignments()
            ->get()
            ->map(fn (PlatformAdminRole $assignment): PlatformRole => $assignment->role)
            ->all();
    }

    public function hasRole(PlatformRole $role): bool
    {
        return in_array($role, $this->roles(), true);
    }
}
