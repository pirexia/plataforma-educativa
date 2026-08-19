<?php

namespace App\Modules\Core\Domain\Models;

use App\Support\Tenancy\TenantModel;

/**
 * ADR-038 §8.3, datos.md §A.5. Registro técnico de deduplicación, sin
 * `public_id` (nunca se expone en URL ni cuerpo) y no auditable (no es una
 * entidad de negocio). La purga es física (forceDelete), no borrado
 * lógico — ver PurgeExpiredIdempotencyKeys.
 */
class IdempotencyKey extends TenantModel
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'endpoint',
        'idempotency_key',
        'request_body_hash',
        'status',
        'response_status',
        'response_body',
        'expires_at',
    ];

    protected $casts = [
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];
}
