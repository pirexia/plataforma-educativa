<?php

namespace App\Modules\Backoffice\Application;

use App\Support\Api\ApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * ADR-038 §4.4, api.md §3.2. Mismo mecanismo que
 * `App\Modules\Core\Application\CursorCodec` (cursor opaco, cifrado,
 * nunca legible) pero sin importarlo (INV-007: no es una interfaz
 * pública de `REQ-CORE`, es su clase de aplicación interna). Aquí no hay
 * tenant: el campo `t` del cursor lleva el `platform_admin_id` del
 * emisor, con la misma finalidad — que un cursor emitido por otra sesión
 * no valga — y el mismo 422 al no cuadrar.
 */
final class PlatformCursorCodec
{
    private const VERSION = 1;

    public function encode(string $occurredAt, int $id, string $filtersFingerprint, int $platformAdminId): string
    {
        return Crypt::encryptString(json_encode([
            'v' => self::VERSION,
            'k' => [$occurredAt, $id],
            'f' => $filtersFingerprint,
            't' => $platformAdminId,
        ]));
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function decode(string $encoded, string $filtersFingerprint, int $platformAdminId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalid();
        }

        if (($payload['f'] ?? null) !== $filtersFingerprint || ($payload['t'] ?? null) !== $platformAdminId) {
            throw $this->invalid();
        }

        return [$payload['k'][0], (int) $payload['k'][1]];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function fingerprint(array $filters): string
    {
        ksort($filters);

        return hash('sha256', json_encode($filters));
    }

    private function invalid(): ApiException
    {
        return ApiException::validation(['cursor' => [[
            'code' => 'bo.validation.cursor_invalid',
            'message' => __('bo.validation.cursor_invalid'),
            'params' => [],
        ]]]);
    }
}
