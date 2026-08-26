<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * funcional.md §C.4.3. Genera y regenera los códigos de respaldo.
 * `RN-AUTH-56`: solo el hash SHA-256 se persiste; el valor en claro sale
 * del servidor una única vez, en la respuesta que lo genera.
 */
final class MfaRecoveryCodeService
{
    /**
     * Crockford base32: 32 símbolos, ya sin I/L/O/U (ambigüedad visual),
     * `§C.4.3` punto 2.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const CODE_LENGTH = 10;

    /**
     * Genera el primer lote, sin comprobar contraseña: se llama justo tras
     * confirmar el primer factor del usuario, dentro de la misma
     * transacción (`§C.4.1` punto 8).
     *
     * @return list<string> códigos en claro, formateados `XXXXX-XXXXX`
     */
    public function generateInitialBatch(User $user): array
    {
        return $this->generate($user);
    }

    /**
     * `POST /auth/mfa-recovery-codes`. `RN-AUTH-60`: exige la contraseña
     * actual. Borra el lote anterior entero (usados incluidos) y crea uno
     * nuevo (`CA-AUTH-112`).
     *
     * @return list<string>
     *
     * @throws ApiException validation() (422)
     */
    public function regenerate(User $user, string $currentPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            $errors = new ValidationErrorBag;
            $errors->add('current_password', 'auth.validation.current_password_incorrect', 'auth.validation.current_password_incorrect');
            $errors->throwIfAny();
        }

        return DB::transaction(function () use ($user): array {
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

            return $this->generate($user);
        });
    }

    /**
     * @return list<string>
     */
    private function generate(User $user): array
    {
        $count = (int) config('auth-local.mfa.recovery_code_count');
        $batchId = (string) Str::ulid();
        $plainCodes = [];

        foreach (range(1, $count) as $ignored) {
            $plainCodes[] = $this->randomCode();
        }

        foreach ($plainCodes as $code) {
            MfaRecoveryCode::create([
                'user_id' => $user->id,
                'code_hash' => $this->hash($code),
                'batch_id' => $batchId,
            ]);
        }

        // §C.4.3 punto 2: agrupado XXXXX-XXXXX solo en la presentación —
        // el hash se calcula sobre el valor canónico sin guion (self::hash()).
        return array_map(
            static fn (string $code): string => substr($code, 0, 5).'-'.substr($code, 5, 5),
            $plainCodes,
        );
    }

    private function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * El guion es solo de presentación (`§C.4.3` punto 2): se normaliza
     * antes de hashear, tanto al generar como al verificar
     * (`MfaChallengeService::verifyRecoveryCode()`).
     */
    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(str_replace('-', '', $code)));
    }
}
