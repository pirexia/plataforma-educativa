<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\Events\RecoveryCodeUsed;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Modules\Auth\Infrastructure\Jobs\SendRecoveryCodeUsedEmail;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * funcional.md §C.4.4, §C.6. El paso 2 del login: abrir el desafío,
 * cambiar de método/reenviar (`§C.4.4.1`) y verificarlo. `session_id`
 * (RN-AUTH-53) es la única credencial — ningún método de esta clase acepta
 * el `public_id` del desafío como entrada.
 */
final class MfaChallengeService
{
    public function __construct(
        private readonly MfaVerifier $totpVerifier,
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenantContext,
        private readonly LoginAttemptRecorder $attempts,
        private readonly AuthenticatedSessionEstablisher $establisher,
    ) {}

    /**
     * `§C.4.4`, apertura del desafío (contraseña ya verificada por
     * `LoginService::attempt()`, `MfaPolicy::hasUsableFactor()` ya en
     * `true`). Llamado por `SessionController::store()`.
     *
     * @return array<string, mixed>
     */
    public function open(Request $request, User $user): array
    {
        $method = $this->pickMethod($user);
        $ttlMinutes = (int) config('auth-local.mfa.challenge_ttl_minutes');

        $challenge = MfaChallenge::create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            'method' => $method,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'ip_address' => $request->ip(),
        ]);

        $this->assertDeliverable($method);

        // §C.4.4 punto 4: pendiente de segundo factor, no cuenta ni pone
        // a cero el bloqueo (RN-AUTH-63). users.email ya se guarda
        // normalizado (LoginService::normalize() en el alta/login).
        $this->attempts->recordPendingSecondFactor($user->email, $user);

        return $this->present($challenge, $user);
    }

    /**
     * `POST /auth/mfa-challenges`, `§C.4.4.1`. No reinicia intentos ni
     * caducidad (`RN-AUTH-54`).
     *
     * @throws ApiException gone() (410), validation() (422)
     */
    public function changeMethod(Request $request, MfaMethod $newMethod): array
    {
        $challenge = $this->findLiveChallengeForSession($request);

        if ($challenge === null) {
            throw ApiException::gone();
        }

        $user = $challenge->user;
        $usable = $this->usableMethods($user);

        if (! in_array($newMethod, $usable, true)) {
            throw ApiException::validation([
                'method' => [[
                    'code' => 'auth.validation.mfa_method_not_available',
                    'message' => __('auth.validation.mfa_method_not_available'),
                    'params' => [],
                ]],
            ]);
        }

        $challenge->method = $newMethod;
        $challenge->deliveries++;
        $challenge->save();

        $this->assertDeliverable($newMethod);

        return $this->present($challenge, $user);
    }

    /**
     * `POST /auth/mfa-verifications`, `§C.4.4` puntos 6-11. Devuelve el
     * mismo resultado que un login de un paso.
     *
     * @throws ApiException gone() (410), unauthenticated() (401)
     */
    public function verify(
        Request $request,
        ?string $code,
        ?string $recoveryCode,
        ?string $deviceCookieValue,
    ): AuthenticatedSessionResult {
        $challenge = $this->findLiveChallengeForSession($request);

        if ($challenge === null) {
            throw ApiException::gone();
        }

        $user = $challenge->user;
        $normalizedEmail = $user->email;

        $recoveryRow = null;
        $validatedStep = null;

        if ($recoveryCode !== null) {
            $recoveryRow = MfaRecoveryCode::query()
                ->where('user_id', $user->id)
                ->where('code_hash', MfaRecoveryCodeService::hash($recoveryCode))
                ->whereNull('used_at')
                ->first();
        } elseif ($code !== null && $challenge->method === MfaMethod::Totp) {
            $factor = $this->confirmedTotpFactor($user);

            if ($factor !== null) {
                $validatedStep = $this->totpVerifier->verify($factor->secret_encrypted, $code, $factor->last_used_step);
            }
        }

        if ($recoveryRow === null && $validatedStep === null) {
            $challenge->attempts++;
            $maxAttempts = (int) config('auth-local.mfa.max_attempts');

            if ($challenge->attempts >= $maxAttempts) {
                $challenge->consumed_at = now();
            }

            $challenge->save();

            // RN-AUTH-64: cuenta hacia el mismo bloqueo que una contraseña.
            $this->attempts->recordSecondFactorInvalid($normalizedEmail, $user);

            throw ApiException::unauthenticated();
        }

        // RN-AUTH-57: el consumo del código de respaldo se escribe en la
        // MISMA transacción que crea la sesión — envolviendo también
        // AuthenticatedSessionEstablisher::establish(), no antes.
        return DB::transaction(function () use (
            $challenge, $recoveryRow, $validatedStep, $user, $request, $normalizedEmail, $deviceCookieValue,
        ): AuthenticatedSessionResult {
            $challenge->consumed_at = now();
            $challenge->save();

            if ($recoveryRow !== null) {
                $recoveryRow->used_at = now();
                $recoveryRow->used_ip = $request->ip();
                $recoveryRow->save();
            } else {
                $factor = $this->confirmedTotpFactor($user);
                $factor->last_used_step = $validatedStep;
                $factor->last_used_at = now();
                $factor->save();
            }

            $result = $this->establisher->establish($request, $user, $normalizedEmail, $deviceCookieValue);

            if ($recoveryRow !== null) {
                event(new RecoveryCodeUsed($this->tenantContext->tenantId(), $user->public_id, $request->ip()));

                SendRecoveryCodeUsedEmail::dispatch(
                    recipientEmail: $user->email,
                    recipientGivenName: $user->person->given_name ?? '',
                    recipientLocale: $user->person->locale ?? 'es-ES',
                    tenantName: Tenant::query()->find($this->tenantContext->tenantId())->name ?? '',
                );
            }

            return $result;
        });
    }

    private function findLiveChallengeForSession(Request $request): ?MfaChallenge
    {
        // RN-AUTH-53: se busca SIEMPRE por (tenant_id, session_id) — la
        // RLS ya acota el tenant. Nunca por public_id.
        return MfaChallenge::query()
            ->where('session_id', $request->session()->getId())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * §C.4.4 punto 2: preferido si lo hay; si no, el único; con varios,
     * TOTP gana.
     */
    private function pickMethod(User $user): MfaMethod
    {
        $factors = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->whereIn('method', $this->settings->mfaAllowedMethods())
            ->get();

        $preferred = $factors->firstWhere('is_preferred', true);

        if ($preferred !== null) {
            return $preferred->method;
        }

        $totp = $factors->firstWhere('method', MfaMethod::Totp);

        return $totp?->method ?? $factors->first()->method;
    }

    /**
     * @return list<MfaMethod>
     */
    private function usableMethods(User $user): array
    {
        return MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->whereIn('method', $this->settings->mfaAllowedMethods())
            ->get()
            ->map(fn (MfaFactor $factor): MfaMethod => $factor->method)
            ->unique(fn (MfaMethod $method): string => $method->value)
            ->values()
            ->all();
    }

    private function confirmedTotpFactor(User $user): ?MfaFactor
    {
        return MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('method', MfaMethod::Totp)
            ->whereNotNull('confirmed_at')
            ->first();
    }

    /**
     * `§C.16`: 1.3 solo implementa TOTP — correo (y SMS) quedan para
     * `1.3b`/proveedor futuro. No debería ser alcanzable en 1.3 porque
     * `MfaEnrollmentService` no permite confirmar un factor de entrega
     * (`self::IMPLEMENTED_METHODS`); esta comprobación es la defensa en
     * profundidad si esa invariante se rompiera en otro punto.
     */
    private function assertDeliverable(MfaMethod $method): void
    {
        if ($method->requiresDelivery()) {
            throw new RuntimeException(
                "Entrega del método {$method->value} no implementada en 1.3 (funcional.md §C.16)."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MfaChallenge $challenge, User $user): array
    {
        $hasUnusedRecoveryCodes = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->exists();

        return [
            'public_id' => $challenge->public_id,
            'method' => $challenge->method->value,
            'available_methods' => array_map(
                fn (MfaMethod $method): string => $method->value,
                $this->usableMethods($user),
            ),
            'expires_at' => $challenge->expires_at->toISOString(),
            'has_unused_recovery_codes' => $hasUnusedRecoveryCodes,
        ];
    }
}
