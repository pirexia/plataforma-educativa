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
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Asignación docente: un usuario imparte una asignatura de un grupo, opcionalmente a un subgrupo (`RN-ACAD-93`).
 *
 * ADR-035 §8: Full — `user_id` es un identificador interno, no un identificador personal de `ADR-035` (a confirmar por `security-reviewer`). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @mixin IdeHelperTeachingAssignment
 */
class TeachingAssignment extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'teaching_assignments';

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
        'user_id',
        'group_id',
        'group_subject_id',
        'subgroup_id',
    ];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id')->withTrashed();
    }

    /**
     * @return BelongsTo<GroupSubject, $this>
     */
    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class, 'group_subject_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Subgroup, $this>
     */
    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(Subgroup::class, 'subgroup_id')->withTrashed();
    }
}
