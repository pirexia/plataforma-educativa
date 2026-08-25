<?php

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\LoginOutcome;
use App\Support\Tenancy\AppendOnlyModel;

/**
 * datos.md §A.1. Append-only: sin created_at/updated_at (usa
 * attempted_at), sin borrado lógico (INV-004 no aplica: un registro de
 * seguridad append-only no se "revierte"). No implementa Auditable a
 * propósito: registrarla en audit_logs duplicaría cada fila y, en un
 * ataque de fuerza bruta, inundaría la tabla de dos años de retención
 * (funcional.md §10.2, datos.md §A.1).
 *
 * @mixin IdeHelperLoginAttempt
 */
class LoginAttempt extends AppendOnlyModel
{
    protected $table = 'login_attempts';

    protected $fillable = [
        'email',
        'user_id',
        'outcome',
        'attempted_at',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'outcome' => LoginOutcome::class,
    ];
}
