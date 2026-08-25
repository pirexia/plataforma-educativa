<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * funcional.md §4.2. Orquesta el orden exacto que RN-AUTH-16/RN-AUTH-23/
 * RN-AUTH-24 exigen: bloqueo antes que credencial, credencial antes que
 * estado. `SessionController` es quien abre la sesión HTTP de verdad
 * (regenerar identificador, guardar payload) — este servicio solo decide
 * si el login es válido y dejarlo dicho en `login_attempts`/`audit_logs`.
 */
final class LoginService
{
    /**
     * Hash bcrypt de un valor que no es contraseña de nadie. RN-AUTH-16 y
     * la nota de RN-AUTH-05 exigen que un correo inexistente tarde lo
     * mismo que uno existente: comparar siempre contra un hash real
     * (nunca `null`) evita el oráculo de tiempo de `password_verify()`
     * contra un valor vacío.
     */
    private const DECOY_HASH = '$2y$12$k13rBy62WMZL2PB2dZz4zuPnzkLke4Pz1XRGyE21EtYNdYaV7Qhru';

    public function __construct(
        private readonly AccountLockService $lockService,
        private readonly LoginAttemptRecorder $attempts,
    ) {}

    /**
     * @throws ApiException accountLocked() (423) o unauthenticated() (401)
     */
    public function attempt(string $email, string $password): User
    {
        $normalizedEmail = $this->normalize($email);

        // RN-AUTH-16: el bloqueo se comprueba ANTES de tocar la
        // contraseña. Comprobarla primero filtraría, por tiempo de
        // respuesta, si era correcta.
        $lockout = $this->lockService->findLiveOrCloseExpired($normalizedEmail);

        if ($lockout !== null) {
            $user = User::query()->where('email', $normalizedEmail)->first();
            $this->attempts->recordLockedAttempt($normalizedEmail, $user);

            throw ApiException::accountLocked();
        }

        $user = User::query()->where('email', $normalizedEmail)->first();

        if (! Hash::check($password, $user->password ?? self::DECOY_HASH)) {
            $this->attempts->recordFailure($normalizedEmail, $user);

            throw ApiException::unauthenticated();
        }

        // A partir de aquí la contraseña era correcta: sin usuario real
        // detrás es imposible (self::DECOY_HASH no verifica contra
        // ninguna entrada), pero PHPStan no lo sabe.
        if ($user === null) {
            throw ApiException::unauthenticated();
        }

        // RN-AUTH-23: solo `activo` inicia sesión. RN-AUTH-24: esto no
        // cuenta como fallo.
        if ($user->status !== UserStatus::Activo) {
            $this->attempts->recordNonActiveState($normalizedEmail, $user);

            throw ApiException::unauthenticated();
        }

        // RN-AUTH-03: reamasado perezoso del coste bcrypt.
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
            $user->save();
        }

        // ADR-039 §4.5/§4.6: el registro de auditoría de 'login' exige un
        // actor ya autenticado (`AuditActor::resolveType()` debe resolver
        // 'user', no 'anonymous'), así que se escribe en el controlador
        // DESPUÉS de `Auth::guard('web')->login($user)`, no aquí — este
        // servicio solo decide si el login es válido.
        $this->attempts->recordSuccess($normalizedEmail, $user);

        return $user;
    }

    public function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
