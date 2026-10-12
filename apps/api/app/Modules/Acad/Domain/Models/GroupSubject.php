<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-ACAD/datos.md §1.6 (1.11-b, `REQ-ACAD-001`). Asignatura que cursa un grupo; el par es la «clase» de ADR-063 §5.3. `course_id` lo fija el servicio a partir del grupo.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @mixin IdeHelperGroupSubject
 */
class GroupSubject extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'group_subjects';

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
        'group_id',
        'subject_id',
    ];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
