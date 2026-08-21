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
