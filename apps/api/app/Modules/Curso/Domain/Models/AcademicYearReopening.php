<?php

namespace App\Modules\Curso\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\AppendOnlyModel;
use Illuminate\Support\Facades\Date;

/**
 * REQ-CURSO/datos.md §1.5 (1.11c, `ADR-059 §5.4`, `RN-CURSO-43`/`-44`). Registro
 * *append-only* de una reapertura: curso, motivo, actor y momento. Sin
 * `updated_at`, `updated_by` ni `deleted_at`.
 *
 * Extiende `AppendOnlyModel` (como `MfaReset`, el precedente que cita
 * `datos.md §1.5`) y no `TenantModel`: este último escribe `updated_by` y usa
 * `deleted_at`, columnas que la tabla *append-only* no tiene.
 *
 * `reason` se registra **con valor** en `audit_logs` (`AuditValuePolicy::Full`,
 * criterio de `ADR-035 §8` aplicado a `user_mfa_exemptions.reason`: contenido
 * del centro); el manual y la interfaz advierten de no escribir datos
 * personales en él.
 *
 * @mixin IdeHelperAcademicYearReopening
 */
class AcademicYearReopening extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'academic_year_reopenings';

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
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // `AppendOnlyModel` no gestiona marcas de tiempo: la tabla solo tiene
        // `created_at`, y el actor es siempre el usuario autenticado.
        static::creating(function (self $model): void {
            $model->created_at ??= Date::now();
            $model->created_by ??= AuditActor::resolveUserId();
        });
    }
}
