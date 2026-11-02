<?php

namespace App\Modules\Prl\Application;

use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-PRL/funcional.md `RN-PRL-10` enmendada, `DP-11` (1.14b-b, decisión del usuario del 2026-11-02, misma línea que `D-66` de
 * `REQ-FAM-UNIT`): las lecturas de `prl` que devuelven teléfonos o nombres de personas dejan una fila `read` **sin valores**
 * (`ADR-035`, `AuditRecorder::recordRead()`) escrita **antes** de devolver los datos (si falla, no sale ninguno) y consumen un
 * cupo de lectura por centro y usuario (`config('prl.read_rate_limit')`, `429` con `Retry-After`). El cupo se consume al
 * empezar, con todos los intentos, para que no sirva de sondeo.
 */
final class PrlReadGuard
{
    public const PURPOSE_ALERT = 'puerta_alerta';

    public const PURPOSE_HISTORY = 'puerta_historial';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function limit(): void
    {
        $userId = AuditActor::resolveUserId();
        $key = $this->tenant->rateLimitKey('prl-read:'.($userId ?? 'system'));
        $attempts = (int) config('prl.read_rate_limit.attempts', 300);
        $decay = (int) config('prl.read_rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'prl.errors.read_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }

    /**
     * La fila `read` sobre el recurso cuyos datos se devuelven; `context` solo lleva la finalidad y recuentos.
     *
     * @param  string  $alias  `pickup_alert` o `student_movement`
     * @param  self::PURPOSE_*  $purpose
     */
    public function audit(string $alias, int $id, string $publicId, string $purpose, int $entries): void
    {
        $this->audit->recordRead($alias, $id, $publicId, ['purpose' => $purpose, 'entries' => $entries]);
    }
}
