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
 * REQ-ACAD/datos.md §1.7, §4 (1.11d-a, `REQ-ACAD-001`). Período lectivo de una
 * etapa del mismo curso académico (FK compuesta).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso será por las interfaces de `Acad\Domain` cuando haya
 * consumidor (`ADR-048`).
 *
 * @mixin IdeHelperAcademicPeriod
 */
class AcademicPeriod extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'academic_periods';

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
        'period_type',
        'name_translations',
        'starts_on',
        'ends_on',
        'position',
    ];

    protected $casts = [
        'name_translations' => 'array',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'position' => 'integer',
    ];

    /**
     * Con borradas: una etapa borrada con períodos vivos (solo tras volver a
     * una versión anterior a `RN-ACAD-61`, `operacion.md §11.6`) no debe romper
     * la representación del período huérfano.
     *
     * @return BelongsTo<EducationLevel, $this>
     */
    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id')->withTrashed();
    }
}
