<?php

namespace App\Modules\Core\Application;

use App\Support\Api\ApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * ADR-038 §4.4: cursor opaco y cifrado (AES-256-GCM vía `Crypt`), nunca
 * base64 legible. Transporta la tupla de orden `(occurred_at, id)`, una
 * huella de los filtros de la petición y el `tenant_id` del emisor. Un
 * cursor que no descifra, con otros filtros, o de otro tenant: 422 sin
 * consulta a base de datos — quien llama decide el mensaje exacto.
 */
final class CursorCodec
{
    private const VERSION = 1;

    public function encode(string $occurredAt, int $id, string $filtersFingerprint, int $tenantId): string
    {
        return Crypt::encryptString(json_encode([
            'v' => self::VERSION,
            'k' => [$occurredAt, $id],
            'f' => $filtersFingerprint,
            't' => $tenantId,
        ]));
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function decode(string $encoded, string $filtersFingerprint, int $tenantId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ApiException::validation(['cursor' => [[
                'code' => 'core.validation.cursor_invalid',
                'message' => __('core.validation.cursor_invalid'),
                'params' => [],
            ]]]);
        }

        if (($payload['f'] ?? null) !== $filtersFingerprint || ($payload['t'] ?? null) !== $tenantId) {
            throw ApiException::validation(['cursor' => [[
                'code' => 'core.validation.cursor_invalid',
                'message' => __('core.validation.cursor_invalid'),
                'params' => [],
            ]]]);
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
}
