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
 * REQ-ACAD/datos.md §1.9, §4 (1.11d-b, `REQ-ACAD-001`). Valor de una escala
 * cualitativa o tramo de una mixta. Solo se escribe dentro de su escala
 * (`RN-ACAD-80`).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15): la sincronización de `RN-ACAD-88` y el
 * borrado en cascada lógica de `RN-ACAD-76` recorren las instancias.
 * `range_min`/`range_max` sin cast (cadena exacta, RN-ACAD-75).
 *
 * @mixin IdeHelperGradingScaleValue
 */
class GradingScaleValue extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'grading_scale_values';

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
        'grading_scale_id',
        'scale_type',
        'code',
        'label_translations',
        'position',
        'is_passing',
        'range_min',
        'range_max',
    ];

    protected $casts = [
        'label_translations' => 'array',
        'position' => 'integer',
        'is_passing' => 'boolean',
    ];

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'grading_scale_id');
    }
}
