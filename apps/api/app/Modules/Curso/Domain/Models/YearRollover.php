<?php

namespace App\Modules\Curso\Domain\Models;

use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-CURSO/datos.md §1.6 (1.12b, `REQ-CURSO-002`): una copia (ejecución del
 * *rollover*) hacia el curso destino. Estado, selección efectiva, resultado y
 * fallo (`RN-CURSO-67`, `-69`, `-73` a `-75`). Sin datos personales: solo
 * códigos y cuentas (`ADR-035 §8`, política `Full`); los `jsonb` grandes los
 * acota el tope de `ADR-035 §5`.
 *
 * `status` lo escriben solo el servicio y los dos trabajos de `curso`, con
 * transiciones condicionales bajo `FOR UPDATE` (`RN-CURSO-84`). Sin
 * *endpoint* de borrado: una copia no se borra, se revierte (borrado lógico
 * heredado de `TenantModel`).
 *
 * @mixin IdeHelperYearRollover
 */
class YearRollover extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'year_rollovers';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'academic_year_id',
        'source_academic_year_id',
        'status',
        'elements',
        'copy_teaching_assignments',
        'date_offset_days',
        'result',
        'failure',
        'revert_failure',
        'started_at',
        'finished_at',
        'reverted_by',
        'reverted_at',
    ];

    protected $casts = [
        'status' => YearRolloverStatus::class,
        'elements' => 'array',
        'copy_teaching_assignments' => 'boolean',
        'date_offset_days' => 'integer',
        'result' => 'array',
        'failure' => 'array',
        'revert_failure' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function sourceAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id');
    }

    /** @return HasMany<YearRolloverItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(YearRolloverItem::class, 'year_rollover_id')->orderBy('id');
    }
}
