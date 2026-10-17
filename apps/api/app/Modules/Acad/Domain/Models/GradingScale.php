<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-ACAD/datos.md §1.8, §4 (1.11d-b, `REQ-ACAD-001`). Escala de calificación
 * de un curso académico: numérica, cualitativa o mixta.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15). Los campos `numeric(5,2)` no tienen cast:
 * llegan como cadena exacta (`"5.00"`), nunca como `float` (RN-ACAD-75). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las
 * interfaces de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @mixin IdeHelperGradingScale
 */
class GradingScale extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const TYPE_NUMERICA = 'numerica';

    public const TYPE_CUALITATIVA = 'cualitativa';

    public const TYPE_MIXTA = 'mixta';

    /** `RN-ACAD-70`. */
    public const TYPES = [self::TYPE_NUMERICA, self::TYPE_CUALITATIVA, self::TYPE_MIXTA];

    protected $table = 'grading_scales';

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
        'code',
        'name_translations',
        'scale_type',
        'min_value',
        'max_value',
        'decimals',
        'passing_min_value',
    ];

    protected $casts = [
        'name_translations' => 'array',
        'decimals' => 'integer',
    ];

    /**
     * Valores o tramos vivos, en orden de `position` (`api.md §11.2`).
     *
     * @return HasMany<GradingScaleValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(GradingScaleValue::class, 'grading_scale_id')->orderBy('position')->orderBy('id');
    }
}
