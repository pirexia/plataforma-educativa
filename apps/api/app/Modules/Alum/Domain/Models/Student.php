<?php

namespace App\Modules\Alum\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\Alum\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * REQ-ALUM/datos.md §1.1, §4 (1.15-a, `REQ-ALUM-001`). Alumno: faceta de `people` (`ADR-034 §1`). No guarda nombre,
 * documento, fecha de nacimiento ni contacto: son de `Core`.
 *
 * ADR-035: `Full` (solo ids). Escrituras por instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01).
 */
class Student extends TenantModel implements Auditable
{
    use HasAuditableAttributes;

    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'students';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    /** @var list<string> */
    protected $fillable = ['person_id'];

    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }
}
