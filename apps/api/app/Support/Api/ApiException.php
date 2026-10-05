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
     * @param  array<string, list<array{code: string, message: string, params?: array<string, mixed>}>>  $errors
     */
    public static function forbidden(?string $detailKey = null, array $detailParams = [], array $errors = []): self
    {
        return new self(403, 'forbidden', $detailKey, $detailParams, $errors);
    }

    /**
     * REQ-PERM-005, api.md §9.2.1 (RPERM-013 / RN-PERM-24): nadie concede
     * un permiso que no posee. Los datos van en `errors.grant[0].params`
     * (ADR-038 §6.3), nunca como `params` de primer nivel.
     */
    public static function cannotGrantUnheldPermission(string $code, string $scope): self
    {
        $key = 'core.authorization.cannot_grant_unheld_permission';
        $params = ['code' => $code, 'scope' => $scope];

        return self::forbidden($key, $params, [
            'grant' => [['code' => $key, 'message' => __($key, $params), 'params' => $params]],
        ]);
    }

    public static function moduleDisabled(): self
    {
        return new self(403, 'module-disabled');
    }

    /**
     * REQ-AUTH/funcional.md §C.4.9, `api.md §C.1.1`. El muro de alta:
     * usuario obligado a MFA, sin factor, gracia vencida —
     * `RequireMfaEnrollment` es el único emisor.
     */
    public static function mfaEnrollmentRequired(): self
    {
        return new self(403, 'mfa-enrollment-required');
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
     * @param  array<string, list<array{code: string, message: string, params?: array<string, mixed>}>>  $errors
     */
    public static function conflict(string $detailKey, array $detailParams = [], array $errors = []): self
    {
        return new self(409, 'conflict', $detailKey, $detailParams, $errors);
    }

    public static function gone(): self
    {
        return new self(410, 'gone');
    }

    /**
     * REQ-AUTH/api.md §1.1: cuenta bloqueada por intentos fallidos
     * (`REQ-AUTH-001`). 423, no 401/403 — el cliente necesita distinguir
     * "bloqueada" de "credenciales incorrectas" sin analizar texto.
     */
    public static function accountLocked(): self
    {
        return new self(423, 'account-locked');
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

    /**
     * REQ-BO-007, api.md §4, §5. Operación sensible del backoffice sin
     * reautenticación viva. `type` propio para que la interfaz lo
     * distinga de `forbidden` sin analizar texto.
     */
    public static function reauthenticationRequired(): self
    {
        return new self(403, 'reauthentication-required');
    }

    /**
     * REQ-BO-007, api.md §5. Dirección de origen fuera de la lista
     * blanca de plataforma (RN-BO-06).
     */
    public static function ipNotAllowed(): self
    {
        return new self(403, 'ip-not-allowed');
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
