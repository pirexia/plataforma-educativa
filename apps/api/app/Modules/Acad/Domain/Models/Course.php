<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-ACAD/datos.md §1.2 (1.11-b, `REQ-ACAD-001`). Curso de etapa, colgado de una etapa del mismo curso académico.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @mixin IdeHelperCourse
 */
class Course extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'courses';

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
        'education_level_id',
        'code',
        'name_translations',
        'position',
    ];

    protected $casts = [
        'name_translations' => 'array',
        'position' => 'integer',
    ];
}
