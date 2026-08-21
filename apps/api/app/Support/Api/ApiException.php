<?php

namespace App\Support\Api;

use RuntimeException;

/**
 * ADR-038 §6: excepción única para toda respuesta de error estructurada de
 * la API (`application/problem+json`). `type` es el slug del catálogo
 * cerrado de §6.2 (sin el prefijo `urn:pge:error:`, que añade
 * ProblemResponseFactory). Ningún controlador ni servicio construye un
 * cuerpo de error a mano: todos lanzan esta excepción y la renderiza
 * ProblemResponseFactory desde bootstrap/app.php.
 *
 * `errors` sigue exactamente la forma de ADR-038 §6.3: por campo, una
 * lista de {code, message, params}, con el mensaje ya traducido.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string|int|float>  $detailParams
     * @param  array<string, list<array{code: string, message: string, params?: array<string, mixed>}>>  $errors
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly ?string $detailKey = null,
        public readonly array $detailParams = [],
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($type);
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function malformed(?string $detailKey = null, array $detailParams = []): self
    {
        return new self(400, 'malformed', $detailKey, $detailParams);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'unauthenticated');
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function forbidden(?string $detailKey = null, array $detailParams = []): self
    {
        return new self(403, 'forbidden', $detailKey, $detailParams);
    }

    public static function moduleDisabled(): self
    {
        return new self(403, 'module-disabled');
    }

    public static function notFound(): self
    {
        return new self(404, 'not-found');
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'method-not-allowed');
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function conflict(string $detailKey, array $detailParams = []): self
    {
        return new self(409, 'conflict', $detailKey, $detailParams);
    }

    public static function gone(): self
    {
        return new self(410, 'gone');
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function payloadTooLarge(?string $detailKey = null, array $detailParams = []): self
    {
        return new self(413, 'payload-too-large', $detailKey, $detailParams);
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function unsupportedMediaType(?string $detailKey = null, array $detailParams = []): self
    {
        return new self(415, 'unsupported-media-type', $detailKey, $detailParams);
    }

    /**
     * @param  array<string, list<array{code: string, message: string, params?: array<string, mixed>}>>  $errors
     * @param  array<string, string|int|float>  $detailParams
     */
    public static function validation(array $errors, ?string $detailKey = null, array $detailParams = []): self
    {
        return new self(422, 'validation', $detailKey, $detailParams, $errors);
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(429, 'too-many-requests', null, [], [], ['Retry-After' => (string) $retryAfterSeconds]);
    }

    public static function unavailable(int $retryAfterSeconds = 30): self
    {
        return new self(503, 'unavailable', null, [], [], ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
