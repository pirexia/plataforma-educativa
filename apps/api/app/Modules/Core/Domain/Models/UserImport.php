<?php

namespace App\Modules\Core\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * datos.md §A.3. Selective: `original_filename`, las claves de objeto y
 * `error_summary` se redactan como `identifier` — pueden contener nombres
 * de fichero o fragmentos identificativos de personal del centro.
 */
class UserImport extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_imports';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'status', 'row_count', 'error_count', 'created_count',
        'validated_at', 'executed_at', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'original_filename',
        'source_object_key',
        'report_object_key',
        'status',
        'row_count',
        'error_count',
        'created_count',
        'error_summary',
        'send_invitations',
        'validated_at',
        'executed_at',
    ];

    protected $casts = [
        'error_summary' => 'array',
        'send_invitations' => 'boolean',
        'validated_at' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
