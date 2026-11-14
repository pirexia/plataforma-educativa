<?php

namespace App\Modules\Alum\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\Alum\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * REQ-ALUM/datos.md §1.2, §4 (1.15-b, `REQ-ALUM-002`). Matrícula: alumno en un curso académico, un curso de etapa y,
 * opcionalmente, un grupo. «Matrícula viva» = `deleted_at IS NULL` (incluye `baja`).
 *
 * ADR-035: `Full` (fechas, estado e ids no son identificadores personales). Escrituras por instancia, nunca masivas
 * (AR-15). Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperEnrollment
 */
class Enrollment extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'enrollments';

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
        'student_id',
        'academic_year_id',
        'course_id',
        'group_id',
        'status',
        'enrolled_on',
        'withdrawn_on',
        'withdrawal_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enrolled_on' => 'date:Y-m-d',
        'withdrawn_on' => 'date:Y-m-d',
    ];

    protected static function newFactory(): EnrollmentFactory
    {
        return EnrollmentFactory::new();
    }
}
