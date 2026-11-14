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
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Tutoría de un grupo (`RN-ACAD-97`).
 *
 * ADR-035 §8: Full — `user_id` es un identificador interno, no un identificador personal de `ADR-035` (a confirmar por `security-reviewer`). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @mixin IdeHelperGroupTutor
 */
class GroupTutor extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'group_tutors';

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
        'group_id',
        'user_id',
    ];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
