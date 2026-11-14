<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Ancla de serialización del horario de un curso académico (`RN-ACAD-115`, `RN-ACAD-125`).
 *
 * ADR-035 §8: Full — sin datos personales. Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @mixin IdeHelperTimetable
 */
class Timetable extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'timetables';

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
    ];
}
