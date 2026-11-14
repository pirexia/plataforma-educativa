<?php

namespace App\Modules\FamUnit\Application;

use App\Support\Api\ApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * REQ-FAM-UNIT/api.md §3 (1.14-b), `ADR-038 §4.4`: cursor opaco y cifrado (AES-256-GCM vía `Crypt`) del histórico de
 * decisiones de imagen, que se ordena por `(occurred_at DESC, id DESC)`. Transporta esa tupla, una huella de los
 * filtros de la petición y el `tenant_id` del emisor: un cursor que no descifra, con otros filtros o de otro centro es
 * `422`, sin consulta a base de datos. Réplica mínima de la de `Core\Application` (`INV-007`: un módulo no importa
 * código interno de otro).
 */
final class ImageConsentCursor
{
    private const VERSION = 1;

    public function encode(string $occurredAt, int $id, string $fingerprint, int $tenantId): string
    {
        return Crypt::encryptString((string) json_encode([
            'v' => self::VERSION,
            'k' => [$occurredAt, $id],
            'f' => $fingerprint,
            't' => $tenantId,
        ]));
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function decode(string $encoded, string $fingerprint, int $tenantId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $this->invalid();
        }

        if (! is_array($payload) || ($payload['f'] ?? null) !== $fingerprint || ($payload['t'] ?? null) !== $tenantId
            || ! is_string($payload['k'][0] ?? null) || ! is_int($payload['k'][1] ?? null)) {
            throw $this->invalid();
        }

        return [$payload['k'][0], $payload['k'][1]];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function fingerprint(array $filters): string
    {
        ksort($filters);

        return hash('sha256', (string) json_encode($filters));
    }

    private function invalid(): ApiException
    {
        return FamUnitErrors::invalid('cursor', 'fam_unit.validation.cursor_invalid');
    }
}
