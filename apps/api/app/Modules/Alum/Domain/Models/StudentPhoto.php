<?php

namespace App\Modules\Alum\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\Alum\StudentPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * REQ-ALUM/datos.md §1.4, §4 (1.15-c, `REQ-ALUM-003`, `OPEN-ALUM-08` = A). Fotografía de un alumno: una viva por alumno
 * (`RN-ALUM-50`), privada (`ADR-013`), solo `image/jpeg` e `image/png` (`RN-ALUM-51`). No comparte tabla con
 * `pickup_photos` de `fam_unit` (`INV-007`).
 *
 * ADR-035: `Selective`. Se registran `student_id`, `content_type`, `byte_size` y `deleted_at`; `object_key` (la clave del
 * objeto no sale nunca del servidor) se redacta como `identifier` por no estar en la lista. Escrituras por instancia,
 * nunca masivas (AR-15): nada es asignable en masa. Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperStudentPhoto
 */
class StudentPhoto extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<StudentPhotoFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    public const CONTENT_TYPES = ['image/jpeg', 'image/png'];

    protected $table = 'student_photos';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['student_id', 'content_type', 'byte_size', 'deleted_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: el servicio rellena la fotografía por instancia (AR-15). */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    protected static function newFactory(): StudentPhotoFactory
    {
        return StudentPhotoFactory::new();
    }
}
