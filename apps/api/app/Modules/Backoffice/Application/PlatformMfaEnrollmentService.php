<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaRecoveryCode;
use App\Support\Api\ApiException;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, datos.md §2.3, §2.4. Alta de segundo factor: solo TOTP,
 * sin período de gracia y sin exención. Reutiliza `TotpProvisioner` y
 * `MfaVerifier` (`ADR-041`), no el almacenamiento de 1.3
 * (funcional.md §2.3).
 */
final class PlatformMfaEnrollmentService
{
    public function __construct(
        private readonly TotpProvisioner $provisioner,
        private readonly MfaVerifier $verifier,
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    /**
     * @return array{factor: PlatformAdminMfaFactor, otpauth_uri: string}
     */
    public function beginEnrollment(PlatformAdmin $admin): array
    {
        PlatformAdminMfaFactor::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('confirmed_at')
            ->delete();

        $secret = $this->provisioner->generateSecret();

        $factor = PlatformAdminMfaFactor::create([
            'platform_admin_id' => $admin->id,
            'type' => 'totp',
            'secret_encrypted' => $secret,
        ]);

        $uri = $this->provisioner->buildOtpAuthUri($secret, $admin->email, config('app.name'));

        return ['factor' => $factor, 'otpauth_uri' => $uri];
    }

    /**
     * @return list<string> códigos de recuperación en claro, mostrados
     *                      una sola vez
     */
    public function confirm(PlatformAdmin $admin, string $publicId, string $code): array
    {
        $factor = PlatformAdminMfaFactor::query()
            ->where('platform_admin_id', $admin->id)
            ->where('public_id', $publicId)
            ->whereNull('confirmed_at')
            ->first();

        if ($factor === null) {
            throw ApiException::notFound();
        }

        $verifiedStep = $this->verifier->verify($factor->secret_encrypted, $code, null);

        if ($verifiedStep === null) {
            throw ApiException::validation([
                'code' => [['code' => 'bo.mfa.invalid_code', 'message' => __('bo.mfa.invalid_code')]],
            ]);
        }

        $factor->forceFill([
            'confirmed_at' => now(),
            'last_used_step' => $verifiedStep,
            'last_used_at' => now(),
        ])->save();

        $admin->forceFill(['mfa_enrolled_at' => now()])->save();

        $codes = $this->generateRecoveryCodes($admin);

        $this->recorder->record(action: AdminActionLogAction::AdminActualizado, subjectPublicId: $admin->public_id, reason: 'alta de segundo factor', context: ['evento' => 'mfa_confirmado']);

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(PlatformAdmin $admin): array
    {
        PlatformAdminMfaRecoveryCode::query()->where('platform_admin_id', $admin->id)->delete();

        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $plain = Str::upper(Str::random(10));
            $codes[] = $plain;

            PlatformAdminMfaRecoveryCode::create([
                'platform_admin_id' => $admin->id,
                'code_hash' => hash('sha256', $plain),
            ]);
        }

        return $codes;
    }
}
