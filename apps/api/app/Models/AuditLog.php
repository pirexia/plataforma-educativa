<?php

namespace App\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\AppendOnlyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ADR-034 §3: tabla única polimórfica, append-only. El registro
 * automático desde el ciclo de vida del ORM (con la lista de redacción
 * por modelo) lo escribe el paso 0.9 — este modelo solo fija el esquema y
 * la relación polimórfica.
 */
class AuditLog extends AppendOnlyModel
{
    use HasPublicId;

    protected $fillable = [
        'occurred_at',
        'actor_user_id',
        'actor_type',
        'auditable_type',
        'auditable_id',
        'auditable_public_id',
        'event',
        'changes',
        'ip_address',
        'user_agent',
        'request_id',
        'context',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'changes' => 'array',
        'context' => 'array',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
