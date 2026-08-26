<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.3. Sin `public_id`: no se expone individualmente por
 * ninguna API. Cuelga del usuario y no del factor (funcional.md §C.4.5).
 *
 * Selective: `code_hash` se declara secreto explícitamente, además de
 * encajar en el patrón global `*recovery_code*` — un hash SHA-256 de un
 * valor de ~50 bits es material atacable por fuerza bruta si se filtra, y
 * `audit_logs` es exportable a CSV (`REQ-CORE-005`).
 *
 * @mixin IdeHelperMfaRecoveryCode
 */
class MfaRecoveryCode extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'user_mfa_recovery_codes';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['used_at', 'batch_id', 'deleted_at', 'created_by', 'updated_by'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['code_hash'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
        'used_ip',
        'batch_id',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
