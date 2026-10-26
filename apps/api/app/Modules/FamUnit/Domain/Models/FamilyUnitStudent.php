<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\FamUnit\FamilyUnitStudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §1.3, §4 (1.14-a, `REQ-FAM-UNIT-001`). Pertenencia de la persona de un alumno a una unidad
 * (`funcional.md §7.4`, `OPEN-FAM-UNIT-01`).
 *
 * ADR-035: `Full`. Solo ids internos. Escrituras por instancia, nunca masivas (AR-15). Los demás módulos no usan este
 * modelo (AR-01): la escritura de otros módulos (`REQ-ALUM`, 1.15) es por `FamilyUnitStudents` (`funcional.md §7.4`).
 *
 * @mixin IdeHelperFamilyUnitStudent
 */
class FamilyUnitStudent extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<FamilyUnitStudentFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'family_unit_students';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    /** @var list<string> */
    protected $fillable = ['family_unit_id', 'student_person_id'];

    protected static function newFactory(): FamilyUnitStudentFactory
    {
        return FamilyUnitStudentFactory::new();
    }

    /**
     * @return BelongsTo<FamilyUnit, $this>
     */
    public function familyUnit(): BelongsTo
    {
        return $this->belongsTo(FamilyUnit::class, 'family_unit_id');
    }
}
