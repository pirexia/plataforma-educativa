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
 * REQ-ACAD/datos.md §1.4 (1.11-b, `REQ-ACAD-001`). Subgrupo de un único grupo (RN-ACAD-16).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @mixin IdeHelperSubgroup
 */
class Subgroup extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'subgroups';

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
        'code',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
