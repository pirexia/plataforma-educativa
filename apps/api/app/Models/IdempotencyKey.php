<?php

namespace App\Models;

use App\Support\Tenancy\TenantModel;

/**
 * ADR-038 §8.3, datos.md §A.5. Registro técnico de deduplicación, sin
 * `public_id` (nunca se expone en URL ni cuerpo) y no auditable (no es una
 * entidad de negocio). La purga es física (forceDelete), no borrado
 * lógico — ver PurgeExpiredIdempotencyKeys.
 *
 * Vive en `App\Models`, no en `App\Modules\Core\Domain\Models`: datos.md
 * §A.5 es explícito en que esta tabla "no es de REQ-CORE... infraestructura
 * de plataforma que los 53 módulos comparten, igual que audit_logs o
 * data_exports" — mismo criterio que sitúa `AuditLog`/`User`/`Person` aquí
 * y no bajo un módulo. Corrección de ubicación (issue #50): la migración
 * original la creó bajo el namespace de Core por ser 1.1 su primer y único
 * consumidor; se traslada ahora, antes de que un segundo módulo la
 * importe desde el sitio equivocado (`INV-007`).
 *
 * @mixin IdeHelperIdempotencyKey
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
