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
 * REQ-CURSO/datos.md §1.7 (1.12b, `RN-CURSO-73`, `-75`, `-79`): qué fila creó
 * cada copia. *Append-only*, polimórfica sin FK (`entity_type` + `target_id`).
 * Extiende `AppendOnlyModel` (precedente `AcademicYearReopening`).
 *
 * **Sin exclusión de eventos** (`OPEN-CURSO-37` = b, decisión del usuario del
 * 2026-10-21): cada *item* deja su propia entrada `created` en `audit_logs`,
 * además de la de la fila copiada. No se toca la lista cerrada de exclusiones
 * de `ADR-040` (`CA-CURSO-150`). Se inserta por instancia a través de este
 * modelo (nunca masivamente: saltaría la auditoría, `AR-15`).
 *
 * @mixin IdeHelperYearRolloverItem
 */
class YearRolloverItem extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'year_rollover_items';

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
        'year_rollover_id',
        'module_code',
        'entity_type',
        'source_id',
        'target_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'source_id' => 'integer',
        'target_id' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // `AppendOnlyModel` no gestiona marcas de tiempo ni autoría: el actor es el
        // usuario fijado por el trabajo (`RN-CURSO-83`), como `created_by` de la copia.
        static::creating(function (self $model): void {
            $model->created_at ??= Date::now();
            $model->created_by ??= AuditActor::resolveUserId();
        });
    }
}
