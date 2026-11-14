<?php

namespace App\Modules\Backoffice\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * `ADR-038 §8` (1.6c). Versión de plataforma de `App\Models\
 * IdempotencyKey` — ver el docblock de la migración para el porqué de
 * que exista una segunda tabla en vez de reutilizar la de tenant: esa es
 * `TenantModel` y exige contexto de tenant activo, que el backoffice
 * nunca tiene.
 *
 * Sin `public_id` (nunca se expone) y no auditable (registro técnico, no
 * una entidad de negocio) — mismo criterio que su homóloga de tenant.
 *
 * @property int $id
 * @property string $endpoint
 * @property string $idempotency_key
 * @property string $request_body_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon $expires_at
 */
class PlatformIdempotencyKey extends Model
{
    protected $table = 'platform_idempotency_keys';

    protected $connection = 'pgsql_platform';

    public $timestamps = false;

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
