<?php

namespace App\Modules\Prl\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\AppendOnlyModel;

/**
 * REQ-PRL/datos.md §8.4, §8.7 (1.14b-b, `REQ-PRL-004`, `RN-PRL-04`, `RN-PRL-05`). Un movimiento de un alumno en la puerta:
 * una entrada o una salida con quién recoge, con qué base figuraba y cómo se verificó. **Inmutable**
 * (`AppendOnlyModel` y `REVOKE UPDATE, DELETE` en el motor, `CA-PRL-004`): un error se corrige con otro movimiento que
 * lo referencia (`corrects_movement_id`). No guarda personas autorizadas ni el estado de una autorización (`RN-PRL-01`):
 * `basis` y las dos referencias son evidencia de lo que `AuthorizedPickupList` dijo en la transacción.
 *
 * ADR-035: `Selective`. Se registran los códigos y marcas (dirección, fuera de horario y su motivo, base, método de
 * verificación, instante); las claves foráneas a alumno, persona, vínculo, autorización y usuarios, las notas, el motivo de
 * corrección y la clave de idempotencia se redactan por no estar en la lista (`datos.md §8.7`). Escrituras por instancia
 * (AR-15). Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperStudentMovement
 */
class StudentMovement extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const DIRECTION_ENTRY = 'entrada';

    public const DIRECTION_EXIT = 'salida';

    public const DIRECTIONS = [self::DIRECTION_ENTRY, self::DIRECTION_EXIT];

    /** Vocabulario cerrado del motivo de un movimiento fuera de horario (`RN-PRL-07`, [DERIVADA]). */
    public const OFF_SCHEDULE_REASONS = ['medico', 'familiar', 'actividad', 'retraso', 'otro'];

    public const VERIFICATION_PHOTO = 'foto';

    public const VERIFICATION_DOCUMENT = 'documento';

    public const VERIFICATION_PHOTO_AND_DOCUMENT = 'foto_y_documento';

    public const VERIFICATION_METHODS = [self::VERIFICATION_PHOTO, self::VERIFICATION_DOCUMENT, self::VERIFICATION_PHOTO_AND_DOCUMENT];

    public const NOTES_MAX_LENGTH = 500;

    protected $table = 'student_movements';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['direction', 'off_schedule', 'off_schedule_reason', 'basis', 'verification_method', 'occurred_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena el movimiento por instancia (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'off_schedule' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        // `AppendOnlyModel` no gestiona marcas de tiempo ni autoría; el servicio fija `recorded_by` y, si no, el usuario
        // autenticado.
        static::creating(function (self $model): void {
            $model->recorded_by ??= AuditActor::resolveUserId();
        });
    }
}
