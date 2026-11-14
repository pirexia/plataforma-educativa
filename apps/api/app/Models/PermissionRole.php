<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-034 §2: concesión de un permiso a un rol del tenant.
 *
 * REQ-PERM/funcional.md §12.1, `datos.md §5.2` (1.5, issue #165): pasa a
 * `Auditable` con política `Full` — un código de permiso, un código de rol,
 * un efecto y un ámbito no son datos personales (`ADR-035 §2`). No declara
 * `auditExcludedEvents()`: `created` se registra (`ADR-040 §4.4` fija que
 * `UserSession` es la única exclusión del repositorio).
 *
 * **Toda escritura pasa por el modelo** (`create()`/`save()`/`delete()`),
 * nunca por `attach()`/`detach()`/`sync()` sobre una relación
 * `belongsToMany` — esas no disparan eventos de modelo y dejarían esta
 * auditoría muda en la práctica (`RN-PERM-19`). Es exactamente lo que hoy
 * deja sin rastro a `role_user` (`datos.md §5.3`).
 *
 * @mixin IdeHelperPermissionRole
 */
class PermissionRole extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'permission_role';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

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
