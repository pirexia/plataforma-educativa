<?php

namespace App\Modules\Acad\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-ACAD/datos.md §9.2, §9.6 (1.12-b, `REQ-ACAD-002`), `ADR-065`. Reserva de un espacio en una fecha y un intervalo horario (`RN-ACAD-140`). El reservante es `created_by`.
 *
 * ADR-035 §8: Full — sin datos personales (`created_by` es un identificador interno). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @mixin IdeHelperFacilityBooking
 */
class FacilityBooking extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'facility_bookings';

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
        'academic_year_id',
        'facility_id',
        'booked_on',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'booked_on' => 'date',
    ];

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id')->withTrashed();
    }
}
