<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\AppendOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.6.1. Traza *append-only* del restablecimiento de MFA por el
 * administrador (RN-AUTH-66). `reason` se registra **con valor, a
 * propósito** — es exactamente la información que `REQ-AUTH-003` exige
 * conservar y `ADR-035` redactaría si viviera solo en `audit_logs.changes`.
 *
 * @mixin IdeHelperMfaReset
 */
class MfaReset extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'mfa_resets';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['reason', 'factors_removed', 'performed_by', 'performed_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    protected $fillable = [
        'user_id',
        'reason',
        'factors_removed',
        'performed_by',
        'performed_at',
        'request_id',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
