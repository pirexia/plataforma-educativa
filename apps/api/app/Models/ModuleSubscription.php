<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * ADR-034 §5: dato del tenant, con RLS. Ausencia de fila = módulo
 * desactivado (falla en cerrado) — comprobarlo es responsabilidad del
 * middleware EnsureModuleEnabled (1.1/1.6), no de este modelo.
 *
 * ADR-035 §8: Full — `settings` queda acotado por el tope de tamaño de
 * config('audit.max_value_length'), no por clasificación.
 *
 * @mixin IdeHelperModuleSubscription
 */
class ModuleSubscription extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    /**
     * `RN-BO-82`, `datos.md §7.7` (1.6c): columnas que el `GRANT SELECT`
     * por columnas concede a `plataforma_app` tras el `REVOKE SELECT` de
     * tabla — `reason`, `created_by` y `updated_by` quedan fuera. Todo
     * `SELECT` de esta tabla alcanzable desde el *runtime* de un centro
     * debe proyectar exactamente esta lista, nunca `SELECT *`: sin
     * proyección explícita, el motor lo rechaza por falta de privilegio
     * de columna (`CA-BO-147`).
     *
     * @var list<string>
     */
    public const TENANT_VISIBLE_COLUMNS = [
        'id',
        'tenant_id',
        'public_id',
        'module_code',
        'enabled',
        'enabled_at',
        'disabled_at',
        'settings',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'module_code',
        'enabled',
        'enabled_at',
        'disabled_at',
        'reason',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'settings' => 'array',
    ];
}
