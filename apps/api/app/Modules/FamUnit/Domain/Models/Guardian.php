<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\FamUnit\GuardianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-FAM-UNIT/datos.md §1.2, §4 (1.14-a, `REQ-FAM-UNIT-001`). Tutor: faceta de `people` (`ADR-034 §1`) que pertenece
 * a una sola unidad. No guarda nombre, correo ni teléfono.
 *
 * ADR-035: `Full`. Solo ids internos y la unidad, ningún dato personal. Escrituras por instancia, nunca masivas
 * (AR-15). Los demás módulos no usan este modelo (AR-01).
 */
class Guardian extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<GuardianFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'guardians';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    /** @var list<string> */
    protected $fillable = ['person_id', 'family_unit_id'];

    protected static function newFactory(): GuardianFactory
    {
        return GuardianFactory::new();
    }

    /**
     * @return BelongsTo<FamilyUnit, $this>
     */
    public function familyUnit(): BelongsTo
    {
        return $this->belongsTo(FamilyUnit::class, 'family_unit_id');
    }

    /**
     * Vínculos vivos del tutor con alumnos. Para escrituras en cascada y recuentos del propio módulo
     * (`RN-FAM-UNIT-12`, `-38`); las consultas de los vínculos pasan por `GuardianLinkService` (`AR-10`).
     *
     * @return HasMany<GuardianStudentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(GuardianStudentLink::class, 'guardian_id');
    }
}
