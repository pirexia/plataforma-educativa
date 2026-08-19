<?php

namespace App\Modules\Core\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §A.4. Full: no contiene datos personales, solo qué se pidió,
 * cuándo y por quién. Primitiva compartida (ExportRequestService,
 * INV-007) — otros módulos la usan por interfaz, no importando esta clase.
 */
class DataExport extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'data_exports';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'kind',
        'format',
        'filters',
        'status',
        'object_key',
        'row_count',
        'error_code',
        'requested_by',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
