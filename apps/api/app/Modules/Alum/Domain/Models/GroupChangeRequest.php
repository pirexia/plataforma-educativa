<?php

namespace App\Modules\Alum\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\Alum\GroupChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * REQ-ALUM/datos.md §1.3, §4 (1.15-c, `REQ-ALUM-003`, `OPEN-ALUM-13` = A). Solicitud de cambio de grupo de una matrícula:
 * pendiente → aprobada / rechazada / cancelada, con doble control (`RN-ALUM-40..46`).
 *
 * ADR-035: `Selective`. Se registran ids, estado e instante de la decisión; `reason` y `decision_note` (texto libre,
 * `RN-ALUM-45`) se redactan como `identifier` por no estar en la lista. Escrituras por instancia, nunca masivas (AR-15):
 * nada es asignable en masa. Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperGroupChangeRequest
 */
class GroupChangeRequest extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<GroupChangeRequestFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'group_change_requests';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'enrollment_id', 'academic_year_id', 'from_group_id', 'to_group_id', 'status',
        'requested_by', 'decided_by', 'decided_at', 'deleted_at',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena la solicitud por instancia (AR-15). */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function newFactory(): GroupChangeRequestFactory
    {
        return GroupChangeRequestFactory::new();
    }
}
