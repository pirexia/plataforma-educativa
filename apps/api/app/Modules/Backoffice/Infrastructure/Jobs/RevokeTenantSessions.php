<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RN-BO-55, funcional.md §5.4.3, operacion.md §6.1. Solo tras ejecutarse
 * una eliminación: cierra todas las sesiones vivas de los usuarios de ese
 * centro con `baja_usuario` — el mismo vocabulario de `SessionEndReason`
 * que ya posee `REQ-AUTH` (no se amplía: son sesiones de usuarios de
 * tenant, no de plataforma).
 *
 * Encolado por el backoffice, que no tiene tenant (`ADR-033 §8`): el
 * tenant afectado viaja como dato del trabajo y el trabajo entra en su
 * contexto explícitamente con `runFor()`, sin heredar ninguno.
 *
 * `INV-007`: consume la interfaz pública `SessionRevoker` de `REQ-AUTH`
 * (`Auth\Domain`), nunca su almacenamiento interno — mismo patrón con el
 * que `REQ-AUTH` consume las interfaces públicas de `REQ-CORE`
 * (`ADR-048 §1.2`).
 */
class RevokeTenantSessions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $tenantId,
    ) {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(TenantContext $tenantContext, SessionRevoker $sessionRevoker): void
    {
        $tenantContext->runFor($this->tenantId, function () use ($sessionRevoker): void {
            User::query()->chunkById(200, function ($users) use ($sessionRevoker): void {
                foreach ($users as $user) {
                    $sessionRevoker->revokeAllForUser($user, SessionEndReason::BajaUsuario);
                }
            });
        });
    }
}
