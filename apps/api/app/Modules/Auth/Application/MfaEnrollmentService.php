<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\Events\MfaFactorConfirmed;
use App\Modules\Auth\Domain\MfaDeliveryCode;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaEnrollmentCodeEmail;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaFactorActivatedEmail;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §C.4.1, §D.4.1. Alta y confirmación de un factor. TOTP y
 * `email` están implementados (`self::IMPLEMENTED_METHODS`); `sms` sigue
 * sin proveedor (`RN-AUTH-69`, `OPEN-AUTH-18`) — un método puede estar
 * permitido por el centro y no estar todavía implementado por el producto.
 */
final class MfaEnrollmentService
{
    /** @var list<MfaMethod> */
    private const IMPLEMENTED_METHODS = [MfaMethod::Totp, MfaMethod::Email];

    public function __construct(
        private readonly TotpProvisioner $totpProvisioner,
        private readonly MfaVerifier $totpVerifier,
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenantContext,
        private readonly MfaRecoveryCodeService $recoveryCodes,
    ) {}

    /**
     * `POST /auth/mfa-enrollments`, `§C.4.1` puntos 2-4, `§D.4.1`.
     *
     * @throws ApiException validation() (422) o conflict() (409)
     */
    public function start(User $user, MfaMethod $method): MfaEnrollmentResult
    {
        $this->assertMethodAvailable($method);

        $alreadyConfirmed = MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('method', $method)
            ->whereNotNull('confirmed_at')
            ->exists();

        if ($alreadyConfirmed) {
            throw ApiException::conflict('auth.validation.mfa_factor_already_confirmed');
        }

        return $method->requiresDelivery()
            ? $this->startDelivery($user, $method)
            : $this->startTotp($user, $method);
    }

    private function startTotp(User $user, MfaMethod $method): MfaEnrollmentResult
    {
        $secret = $this->totpProvisioner->generateSecret();
        $ttlMinutes = (int) config('auth-local.mfa.enrollment_ttl_minutes');

        $factor = MfaFactor::create([
            'user_id' => $user->id,
            'method' => $method,
            'secret_encrypted' => $secret,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());
        $uri = $this->totpProvisioner->buildOtpAuthUri($secret, $user->email, $tenant->name ?? 'Plataforma');

        return new MfaEnrollmentResult($factor, $secret, $uri);
    }

    /**
     * `RN-AUTH-75`, `RN-AUTH-76`, `RN-AUTH-77`. El destino es siempre
     * `users.email` en el momento del envío, nunca se copia a la fila del
     * factor, y no se devuelve nada verificable en la respuesta.
     */
    private function startDelivery(User $user, MfaMethod $method): MfaEnrollmentResult
    {
        // RN-AUTH-76: como mucho un alta sin confirmar viva por (usuario,
        // método de entrega) — abrir una nueva invalida la anterior.
        MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('method', $method)
            ->whereNull('confirmed_at')
            ->delete();

        $code = MfaDeliveryCode::generate();
        $codeTtlMinutes = (int) config('auth-local.mfa.code_ttl_minutes');
        $enrollmentTtlMinutes = (int) config('auth-local.mfa.enrollment_ttl_minutes');

        $factor = MfaFactor::create([
            'user_id' => $user->id,
            'method' => $method,
            'code_hash' => MfaDeliveryCode::hash($code),
            'code_expires_at' => now()->addMinutes($codeTtlMinutes),
            'expires_at' => now()->addMinutes($enrollmentTtlMinutes),
        ]);

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());

        SendMfaEnrollmentCodeEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: $tenant->name ?? '',
            code: $code,
            ttlMinutes: $codeTtlMinutes,
        );

        return new MfaEnrollmentResult($factor, null, null, DestinationMasker::maskEmail($user->email));
    }

    /**
     * `POST /auth/mfa-factors`, `§C.4.1` puntos 5-11. `$enrollmentPublicId`
     * es el `public_id` del alta provisional abierta por `start()`.
     *
     * @throws ApiException gone() (410, alta inexistente/vencida/ajena),
     *                      validation() (422, código incorrecto)
     */
    public function confirm(User $user, string $enrollmentPublicId, string $code): MfaFactorConfirmationResult
    {
        $factor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('public_id', $enrollmentPublicId)
            ->whereNull('confirmed_at')
            ->first();

        // CA-AUTH-107: mismo cuerpo para alta inexistente y alta vencida.
        if ($factor === null || $factor->isEnrollmentExpired()) {
            throw ApiException::gone();
        }

        $validatedStep = $factor->method === MfaMethod::Totp
            ? $this->totpVerifier->verify($factor->secret_encrypted, $code, null)
            : null;

        // RN-AUTH-75, §D.4.1 punto 7: comparación en tiempo constante
        // contra el hash del alta, y la caducidad propia del código —
        // distinta de expires_at del alta (CA-AUTH-149).
        $deliveryCodeValid = $factor->method->requiresDelivery()
            && $factor->code_hash !== null
            && hash_equals($factor->code_hash, MfaDeliveryCode::hash($code))
            && $factor->code_expires_at !== null
            && now()->lessThan($factor->code_expires_at);

        if ($factor->method === MfaMethod::Totp ? $validatedStep === null : ! $deliveryCodeValid) {
            $this->recordFailedAttempt($factor);

            $errors = new ValidationErrorBag;
            $errors->add('code', 'auth.validation.mfa_code_invalid', 'auth.validation.mfa_code_invalid');
            $errors->throwIfAny();
        }

        $wasFirstConfirmedFactor = ! MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->exists();

        $recoveryCodesInClear = DB::transaction(function () use ($factor, $validatedStep, $user, $wasFirstConfirmedFactor): ?array {
            $factor->confirmed_at = now();
            $factor->expires_at = null;
            // RN-AUTH-75: el código ya no tiene función en cuanto se
            // confirma — es material vivo mientras esté.
            $factor->code_hash = null;
            $factor->code_expires_at = null;
            $factor->last_used_step = $validatedStep;
            $factor->last_used_at = now();
            $factor->save();

            // §C.4.8: "al confirmar su primer factor se cierra la fila" de
            // user_mfa_obligations — si había una abierta, MfaPolicy ya
            // evalúa NoObligado a partir de aquí (hasUsableFactor), y esta
            // fila deja de reflejar el estado real si no se cierra.
            UserMfaObligation::query()
                ->where('user_id', $user->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);

            return $wasFirstConfirmedFactor ? $this->recoveryCodes->generateInitialBatch($user) : null;
        });

        event(new MfaFactorConfirmed($this->tenantContext->tenantId(), $user->public_id, $factor->method->value));

        SendMfaFactorActivatedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: Tenant::query()->find($this->tenantContext->tenantId())->name ?? '',
        );

        return new MfaFactorConfirmationResult($factor, $recoveryCodesInClear);
    }

    /**
     * `RN-AUTH-59`: quinto intento fallido, el alta queda muerta (borrado
     * lógico) y hay que empezar de nuevo — a partir de ahí, cualquier
     * intento contra ese `public_id` cae en el `410` de arriba.
     */
    private function recordFailedAttempt(MfaFactor $factor): void
    {
        $maxAttempts = (int) config('auth-local.mfa.max_attempts');
        $factor->confirmation_attempts++;

        if ($factor->confirmation_attempts >= $maxAttempts) {
            $factor->delete();

            return;
        }

        $factor->save();
    }

    /**
     * @throws ApiException validation() (422)
     */
    private function assertMethodAvailable(MfaMethod $method): void
    {
        $tenantAllows = in_array($method->value, $this->settings->mfaAllowedMethods(), true);
        $productImplements = in_array($method, self::IMPLEMENTED_METHODS, true);

        if (! $tenantAllows || ! $productImplements) {
            $errors = new ValidationErrorBag;
            $errors->add('method', 'auth.validation.mfa_method_not_available', 'auth.validation.mfa_method_not_available');
            $errors->throwIfAny();
        }
    }
}
