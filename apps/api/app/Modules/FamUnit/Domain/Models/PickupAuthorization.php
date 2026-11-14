<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §8.2, §8.7 (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-068`). Autorización de un tercero (permanente) o
 * puntual para recoger a un alumno, con la versión del texto `autorizacion_recogida` que aceptó el tutor principal. La
 * retirada (`withdrawn_at`) cambia su estado y no borra la fila (`RN-FAM-UNIT-87`). **No hay modelo de lista**: la lista
 * de un alumno se calcula en cada consulta (`RN-FAM-UNIT-79`).
 *
 * ADR-035: `Selective`. Se registran `relationship`, `kind`, `valid_from`, `valid_until`, `channel`, `terms_version_id`,
 * `authorized_at` y `withdrawn_at`; las claves foráneas a alumno, persona, tutor y usuarios, la IP y el agente de usuario
 * se redactan como `identifier` y `reason` (texto libre) por no estar en la lista. Escrituras por instancia, nunca masivas
 * (AR-15). **Único servicio sancionado por `AR-10` para consultarlo**: `PickupListService` (recurso `autorizado_recogida`);
 * los demás módulos no usan este modelo (AR-01): consultan `AuthorizedPickupList`.
 *
 * @mixin IdeHelperPickupAuthorization
 */
class PickupAuthorization extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const RELATIONSHIPS = ['abuelo', 'personal_apoyo', 'otra_familia', 'otro'];

    public const KIND_PERMANENT = 'permanente';

    public const KIND_PUNCTUAL = 'puntual';

    public const KINDS = [self::KIND_PERMANENT, self::KIND_PUNCTUAL];

    /** `portal` llega con `1.22` y amplía el `CHECK` con su especificación (`datos.md §8.2`). */
    public const CHANNELS = ['presencial', 'telefono'];

    protected $table = 'pickup_authorizations';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'relationship', 'kind', 'valid_from', 'valid_until', 'channel', 'terms_version_id', 'authorized_at', 'withdrawn_at',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena la autorización por instancia (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'authorized_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TermsVersion, $this>
     */
    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'terms_version_id');
    }
}
