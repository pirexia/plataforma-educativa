<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-033 §2, RN-AUTH-31, REQ-AUTH/api.md §8 (posición 6 de la cadena,
 * después de `StartSession`). La sesión guarda el `tenant_id` con el que
 * se autenticó (`RN-AUTH-31`); si no coincide con el tenant resuelto por
 * host en esta petición, la sesión se invalida, se audita y se responde
 * `401` — nunca se sirve una respuesta con datos del tenant equivocado.
 *
 * Sin sesión autenticada (anónimo, o antes del login) no hay nada que
 * reverificar: pasa de largo.
 */
class VerifySessionTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $sessionTenantId = $request->session()->get('pge_tenant_id');

        if ($sessionTenantId === $this->tenantContext->tenantId()) {
            return $next($request);
        }

        // Hallazgo propio de la revisión de seguridad de 1.2b: el
        // registro de auditoría y el cierre de la fila de `user_sessions`
        // tienen que escribirse bajo el tenant REAL de la sesión
        // ($sessionTenantId), no bajo el tenant resuelto por host de esta
        // petición ($this->tenantContext->tenantId()). `BelongsToTenant`
        // asigna `tenant_id` desde el `TenantContext` ACTIVO al crear la
        // fila (el mismo `app.tenant_id` que aplica `TenantScope` en
        // lectura). Sin este `runFor()`:
        //   - `AuditLog::create()` insertaba con `tenant_id` = tenant del
        //     host pero `actor_user_id` = usuario de OTRO tenant,
        //     violando la FK compuesta `audit_logs_actor_fk (tenant_id,
        //     actor_user_id) -> users (tenant_id, id)` — la petición
        //     terminaba en 500 en vez de 401.
        //   - Esa excepción interrumpía el método ANTES de
        //     `Auth::guard('web')->logout()`/`session()->invalidate()`:
        //     la sesión discrepante quedaba viva pese al aviso de
        //     incoherencia.
        //   - Y aunque `AuditLog::create()` no hubiera reventado, la
        //     consulta de `UserSession` (filtrada por `TenantScope` al
        //     tenant del host) nunca encontraba la fila real, así que
        //     nunca se cerraba con `TenantIncoherente` (RN-AUTH-44): el
        //     contador de incidentes de `operacion.md §B.5` se quedaba
        //     siempre a cero.
        if (is_int($sessionTenantId)) {
            $sessionId = $request->session()->getId();

            $this->tenantContext->runFor($sessionTenantId, function () use ($user, $sessionId): void {
                // Se registra antes de destruir la sesión (ADR-039 §4.5):
                // después, Auth::id() ya no resuelve nada y la fila
                // quedaría sin actor. Se audita como 'logout': es el
                // efecto observable real — la sesión deja de existir — y
                // ADR-039 no define un evento propio para esta
                // discrepancia.
                $this->auditRecorder->record($user, 'logout');

                // funcional.md §B.4.6, RN-AUTH-31, RN-AUTH-44:
                // 'tenant_incoherente' es la única razón de cierre que no
                // ocurre en operación normal (operacion.md §B.5) —
                // cualquier valor distinto de cero es un incidente.
                UserSession::query()
                    ->where('session_id', $sessionId)
                    ->whereNull('ended_at')
                    ->first()
                    ?->close(SessionEndReason::TenantIncoherente);
            });
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw ApiException::unauthenticated();
    }
}
