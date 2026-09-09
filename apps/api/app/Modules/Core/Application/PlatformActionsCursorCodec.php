<?php

namespace App\Modules\Core\Application;

use App\Support\Api\ApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * REQ-BO/api.md §2.9, §4.5 (1.6). `GET /api/v1/platform-actions` lee
 * `admin_action_logs` con la conexión `pgsql` del tenant, que solo tiene
 * concedidas seis columnas (datos.md §4.3) — `id` no es una de ellas, así
 * que el desempate estricto de `ADR-038 §4.4` no puede apoyarse en él
 * como `CursorCodec` (que se usa para `audit_logs`, tabla de tenant con
 * `id` disponible). Aquí el desempate es `public_id` (ULID). El emisor
 * del cursor SÍ es el `tenant_id`, igual que `CursorCodec`: esta
 * petición corre dentro de un contexto de tenant normal, a diferencia
 * del listado del backoffice (`App\Modules\Backoffice\Application\
 * PlatformCursorCodec`, emisor `platform_admin_id`, sin tenant).
 */
final class PlatformActionsCursorCodec
{
    private const VERSION = 1;

    public function encode(string $occurredAt, string $publicId, string $filtersFingerprint, int $tenantId): string
    {
        return Crypt::encryptString(json_encode([
            'v' => self::VERSION,
            'k' => [$occurredAt, $publicId],
            'f' => $filtersFingerprint,
            't' => $tenantId,
        ]));
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function decode(string $encoded, string $filtersFingerprint, int $tenantId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalid();
        }

        if (($payload['f'] ?? null) !== $filtersFingerprint || ($payload['t'] ?? null) !== $tenantId) {
            throw $this->invalid();
        }

        return [$payload['k'][0], (string) $payload['k'][1]];
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
            'code' => 'core.validation.cursor_invalid',
            'message' => __('core.validation.cursor_invalid'),
            'params' => [],
        ]]]);
    }
}
