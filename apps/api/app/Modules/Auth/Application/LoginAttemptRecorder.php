<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\LoginOutcome;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * funcional.md §4.2, §4.4, §4.8, datos.md §A.7. Compartido por el login
 * (§4.2) y por el cambio de contraseña auto-servicio (§4.8 punto 4): en
 * ambos, un fallo de credencial cuenta hacia el mismo bloqueo de
 * `(tenant_id, email)`, dejarlo sin contar en uno de los dos sería un
 * rodeo trivial al límite de cinco intentos.
 */
final class LoginAttemptRecorder
{
    public function __construct(
        private readonly AccountLockService $lockService,
    ) {}

    public function recordSuccess(string $email, User $user): void
    {
        $this->write($email, $user, LoginOutcome::Exito);
    }

    /**
     * RN-AUTH-14: quinto fallo consecutivo crea el bloqueo. `$user` es
     * `null` en el bloqueo fantasma (RN-AUTH-15, correo sin cuenta).
     */
    public function recordFailure(string $email, ?User $user): void
    {
        $this->write($email, $user, LoginOutcome::CredencialesInvalidas);

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
    public function recordNonActiveState(string $email, User $user): void
    {
        $this->write($email, $user, LoginOutcome::EstadoNoActivo);
    }

    public function recordLockedAttempt(string $email, ?User $user): void
    {
        $this->write($email, $user, LoginOutcome::CuentaBloqueada);
    }

    private function write(string $email, ?User $user, LoginOutcome $outcome): void
    {
        LoginAttempt::create([
            'email' => $email,
            'user_id' => $user?->id,
            'outcome' => $outcome,
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

            if ($attempt->outcome === LoginOutcome::CredencialesInvalidas) {
                $count++;
            }
        }

        return $count;
    }
}
