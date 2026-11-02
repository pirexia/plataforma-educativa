<?php

namespace App\Modules\Prl\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-PRL/datos.md §8.5, §8.7 (1.14b-b, `REQ-PRL-004`, `RN-PRL-08`). Alerta abierta cuando se presenta una persona que no
 * figura en la lista de un alumno, con su resolución. Se cierra una sola vez (`409 prl.alert.closed` después) y **no se
 * borra**: es evidencia (`INV-005` exige `deleted_at`, pero no hay *endpoint* de borrado). No envía nada por sí sola
 * (`OPEN-FAM-UNIT-33`, hasta `1.19`).
 *
 * ADR-035: `Selective`. Se registran el estado, la resolución y los instantes; el alumno, los usuarios, la descripción de
 * quien se presenta y las notas de resolución (texto libre) se redactan por no estar en la lista. Escrituras por
 * instancia (AR-15).
 *
 * @mixin IdeHelperPickupAlert
 */
class PickupAlert extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const KIND_UNAUTHORIZED_PERSON = 'persona_no_autorizada';

    public const KINDS = [self::KIND_UNAUTHORIZED_PERSON];

    public const STATUS_OPEN = 'abierta';

    public const STATUS_CLOSED = 'cerrada';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED];

    /** Vocabulario cerrado de la resolución (`RN-PRL-08`). */
    public const RESOLUTIONS = ['no_entregado', 'recogido_por_autorizado', 'autorizacion_puntual_registrada', 'otro'];

    public const DESCRIPTION_MAX_LENGTH = 500;

    public const NOTES_MAX_LENGTH = 1000;

    protected $table = 'pickup_alerts';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['kind', 'status', 'raised_at', 'principal_contacted_at', 'resolution', 'resolved_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena la alerta por instancia (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'raised_at' => 'datetime',
            'principal_contacted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
