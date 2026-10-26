<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\FamUnit\FamilyUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §1.1, §4 (1.14-a, `REQ-FAM-UNIT-001`). Unidad familiar: agrupa tutores y alumnado.
 *
 * ADR-035: `Selective`. Se registra el valor de `principal_guardian_id` (un id interno) y de las marcas de borrado y
 * autoría; `code` se redacta como `identifier` (un código de familia identifica indirectamente a una familia
 * concreta, `datos.md §4`). Escrituras por instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `FamUnit\Domain` (`FamilyMembership`, `funcional.md §7`).
 */
class FamilyUnit extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<FamilyUnitFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'family_units';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['principal_guardian_id', 'deleted_at', 'created_by', 'updated_by'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** @var list<string> */
    protected $fillable = ['code', 'principal_guardian_id'];

    protected static function newFactory(): FamilyUnitFactory
    {
        return FamilyUnitFactory::new();
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function principalGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'principal_guardian_id');
    }
}
