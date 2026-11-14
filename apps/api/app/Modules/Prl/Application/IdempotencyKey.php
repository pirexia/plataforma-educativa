<?php

namespace App\Modules\Prl\Application;

/**
 * REQ-PRL/funcional.md `RN-PRL-09` (`INV-011`): la clave `Idempotency-Key` de un `POST` de movimiento o de alerta y la huella
 * de su cuerpo. La deduplicación la hace el índice único parcial `(tenant_id, usuario, clave)` de la propia tabla
 * (`datos.md §8.4`, `§8.5`): la misma clave del mismo usuario con la misma huella devuelve el resultado original; con otra
 * huella, `409 prl.idempotency_mismatch`.
 */
final class IdempotencyKey
{
    public const MAX_LENGTH = 128;

    public function __construct(
        public readonly string $key,
        public readonly string $bodyHash,
    ) {}

    /** Una clave admisible: 1 a 128 caracteres ASCII visibles (sin espacios). */
    public static function isValid(string $key): bool
    {
        return preg_match('/^[\x21-\x7E]{1,'.self::MAX_LENGTH.'}\z/', $key) === 1;
    }

    /**
     * La huella del cuerpo ya validado y normalizado: independiente del orden de las claves y de espacios del JSON original.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashOf(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
