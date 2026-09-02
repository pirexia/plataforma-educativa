<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\LoginMethod;
use App\Modules\Auth\Domain\LoginOutcome;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * funcional.md §4.2, §4.4, §4.8, §C.4.4.2, datos.md §A.7. Compartido por
 * el login (§4.2) y por el cambio de contraseña auto-servicio (§4.8 punto
 * 4): en ambos, un fallo de credencial cuenta hacia el mismo bloqueo de
 * `(tenant_id, email)`, dejarlo sin contar en uno de los dos sería un
 * rodeo trivial al límite de cinco intentos.
 *
 * RN-AUTH-63 (1.3): `recordSuccess()` ya **no** se llama al verificar la
 * contraseña — `LoginService::attempt()` no la invoca. Solo se llama
 * cuando la sesión se ha creado de verdad (`AuthenticatedSessionEstablisher`),
 * para que repetir el paso 1 antes de cada intento de segundo factor no dé
 * intentos ilimitados contra el código.
 */
final class LoginAttemptRecorder
{
    public function __construct(
        private readonly AccountLockService $lockService,
    ) {}

    /**
     * RN-AUTH-63: llamar **solo** cuando la sesión se ha creado de verdad
     * — es lo único que pone el contador de fallos consecutivos a cero.
     * `$method` por defecto `Local`: el camino federado (REQ-AUTH-002,
     * 1.4) lo pasa explícitamente (CA-AUTH-208, CA-AUTH-217).
     */
    public function recordSuccess(string $email, User $user, LoginMethod $method = LoginMethod::Local): void
    {
        $this->write($email, $user, LoginOutcome::Exito, $method);
    }

    /**
     * funcional.md §C.4.4 punto 4, RN-AUTH-63: contraseña correcta, se
     * abre un desafío de segundo factor. No cuenta hacia el bloqueo y,
     * sobre todo, no lo pone a cero.
     */
    public function recordPendingSecondFactor(string $email, User $user, LoginMethod $method = LoginMethod::Local): void
    {
        $this->write($email, $user, LoginOutcome::PendienteSegundoFactor, $method);
    }

    /**
     * RN-AUTH-64: un fallo de segundo factor —código o código de
     * respaldo— incrementa el **mismo** contador que un fallo de
     * contraseña y puede provocar el bloqueo de RN-AUTH-14.
     */
    public function recordSecondFactorInvalid(string $email, User $user, LoginMethod $method = LoginMethod::Local): void
    {
        $this->write($email, $user, LoginOutcome::SegundoFactorInvalido, $method);

        $consecutiveFailures = $this->consecutiveFailures($email);
        $maxAttempts = (int) config('auth-local.max_login_attempts');

        if ($consecutiveFailures >= $maxAttempts) {
            $this->lockService->lock($email, $user, $consecutiveFailures);
        }
    }

    /**
     * RN-AUTH-14: quinto fallo consecutivo crea el bloqueo. `$user` es
     * `null` en el bloqueo fantasma (RN-AUTH-15, correo sin cuenta).
     */
    public function recordFailure(string $email, ?User $user): void
    {
        $this->write($email, $user, LoginOutcome::CredencialesInvalidas, LoginMethod::Local);

        $consecutiveFailures = $this->consecutiveFailures($email);
        $maxAttempts = (int) config('auth-local.max_login_attempts');

        if ($consecutiveFailures >= $maxAttempts) {
            $this->lockService->lock($email, $user, $consecutiveFailures);
        }
    }

    /**
     * RN-AUTH-24: una credencial correcta sobre un usuario no activo no
     * es un fallo, así que no cuenta hacia el bloqueo. Se registra igual
     * (§4.2 punto 4: "cada intento, éxito o fallo, escribe una fila").
     */
    public function recordNonActiveState(string $email, User $user, LoginMethod $method = LoginMethod::Local): void
    {
        $this->write($email, $user, LoginOutcome::EstadoNoActivo, $method);
    }

    public function recordLockedAttempt(string $email, ?User $user, LoginMethod $method = LoginMethod::Local): void
    {
        $this->write($email, $user, LoginOutcome::CuentaBloqueada, $method);
    }

    /**
     * REQ-AUTH-002 (1.4), datos.md §E.3.2: el *callback* termina sin
     * poder resolver un usuario (sin vínculo y, o el correo no venía
     * verificado, o no hay cuenta local). `$method` por defecto `Google`
     * — el camino institucional (1.4b, `datos.md §F.5`) lo pasa
     * explícitamente (`method = 'sso'`). **No cuenta hacia el bloqueo**
     * (RN-AUTH-14 no aplica: no se ha probado ninguna credencial
     * nuestra).
     */
    public function recordFederatedNoLink(string $email, LoginMethod $method = LoginMethod::Google): void
    {
        $this->write($email, null, LoginOutcome::FederadoSinVinculo, $method);
    }

    private function write(string $email, ?User $user, LoginOutcome $outcome, LoginMethod $method): void
    {
        LoginAttempt::create([
            'email' => $email,
            'user_id' => $user?->id,
            'outcome' => $outcome,
            'method' => $method,
            'attempted_at' => now(),
            'ip_address' => RequestFacade::ip(),
            'user_agent' => RequestFacade::userAgent(),
            'request_id' => app(RequestId::class)->current(),
        ]);
    }

    /**
     * datos.md §A.7: consulta caliente. Cuenta los fallos consecutivos
     * desde el último éxito (que la detiene) — las filas de estado
     * intermedio (`estado_no_activo`, `cuenta_bloqueada`) ni cuentan ni
     * reinician el recuento, solo un `exito` lo hace (RN-AUTH-14).
     */
    private function consecutiveFailures(string $email): int
    {
        // Desempate por `id` (bigint autoincremental, orden de inserción
        // real): dos filas pueden compartir `attempted_at` aunque la
        // columna tenga precisión de microsegundo (RN-AUTH-14 existe
        // precisamente para varios intentos por segundo), y sin un
        // segundo criterio ORDER BY, PostgreSQL no garantiza qué fila
        // empatada devuelve primero — se detectó escribiendo esta suite:
        // sin el desempate, un `exito` que compartiera segundo con los
        // fallos siguientes podía no cortar el recuento, bloqueando una
        // cuenta que RN-AUTH-14 dice que debía seguir a cero.
        $recent = LoginAttempt::query()
            ->where('email', $email)
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['outcome']);

        $count = 0;

        foreach ($recent as $attempt) {
            if ($attempt->outcome === LoginOutcome::Exito) {
                break;
            }

            // RN-AUTH-64: un fallo de segundo factor cuenta exactamente
            // igual que un fallo de contraseña. `PendienteSegundoFactor`,
            // `EstadoNoActivo` y `CuentaBloqueada` no cuentan ni cortan el
            // recuento (RN-AUTH-63).
            if ($attempt->outcome === LoginOutcome::CredencialesInvalidas
                || $attempt->outcome === LoginOutcome::SegundoFactorInvalido) {
                $count++;
            }
        }

        return $count;
    }
}
