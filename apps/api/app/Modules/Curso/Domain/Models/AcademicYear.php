<?php

namespace App\Modules\Curso\Domain\Models;

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-CURSO/datos.md §1.2 (1.10, OPEN-CURSO-03): movido desde `App\Models`
 * al módulo `curso`. Los demás módulos NO lo usan (`AR-01`, RN-CURSO-26):
 * acceden al curso por las interfaces de `Curso\Domain`
 * (`AcademicYearContext`, `AcademicYearDirectory`…).
 *
 * ADR-034 §4: del tenant, nunca catálogo compartido — cada centro fija sus
 * propias fechas. academic_year_id es NOT NULL o no existe la columna en
 * cualquier tabla que la referencie; nunca nullable (regla verificada por
 * el test de esquema de 0.8.10, no por este modelo).
 *
 * ADR-035 §8: Full — sin datos personales.
 *
 * @mixin IdeHelperAcademicYear
 */
class AcademicYear extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'code',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'status' => AcademicYearStatus::class,
    ];

    public function toSummary(): AcademicYearSummary
    {
        return new AcademicYearSummary(
            id: $this->id,
            publicId: $this->public_id,
            code: $this->code,
            status: $this->status,
            startsOn: $this->starts_on->toImmutable()->startOfDay(),
            endsOn: $this->ends_on->toImmutable()->startOfDay(),
        );
    }
}
