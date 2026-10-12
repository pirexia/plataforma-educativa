<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-ACAD/datos.md §1.3 (1.11-b, `REQ-ACAD-001`). Grupo de un curso de etapa. `name` es texto simple, no traducible.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @mixin IdeHelperGroup
 */
class Group extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'groups';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'course_id',
        'code',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];
}
