<?php

namespace App\Modules\Backoffice\Domain;

/**
 * `REQ-BO-004`, sub-paso `1.6d`. `funcional.md §5.9.1`, `§5.9.5`,
 * `RN-BO-84`, `RN-BO-90`. Lectura de una fila de `failed_jobs` (objeto
 * genérico de query builder, por `pgsql_platform`) hacia lo poco que
 * `1.6d` puede devolver de ella — nunca el `payload` completo ni la
 * traza. Única implementación, reutilizada por
 * `TenantHealthController::failedJobs()` y por
 * `FailedJobRetryService::tenantIdOf()`.
 */
final class FailedJobPresentation
{
    /**
     * `ADR-033 §8`: `null` cuando el trabajo se despachó sin tenant
     * activo — los cuatro que despacha el backoffice (`RN-BO-90`).
     */
    public static function tenantIdOf(object $row): ?int
    {
        $tenantId = self::decodedPayload($row)['tenant_id'] ?? null;

        return is_int($tenantId) ? $tenantId : null;
    }

    /**
     * `payload.displayName`: un nombre de clase PHP, no un dato personal
     * (`RN-BO-84`).
     */
    public static function jobClassOf(object $row): ?string
    {
        $displayName = self::decodedPayload($row)['displayName'] ?? null;

        return is_string($displayName) ? $displayName : null;
    }

    /**
     * `RN-BO-84`: sólo la clase de la excepción, nunca la traza
     * completa. `exception` guarda `(string) $throwable`: la clase es
     * todo lo que precede al primer `: ` de la primera línea.
     */
    public static function exceptionClassOf(object $row): ?string
    {
        return self::exceptionHeadOf($row)[0];
    }

    /**
     * `OPEN-BO-22` (resuelta: sí): el mensaje de la excepción se
     * devuelve, como excepción consciente y acotada a `RN-BO-33` — sin
     * él, `REQ-BO-004` («diagnóstico de trabajos») se vacía. El sufijo
     * ` in fichero:línea` que añade `Throwable::__toString()` se retira:
     * no aporta diagnóstico y puede arrastrar una ruta de disco.
     */
    public static function exceptionMessageOf(object $row): ?string
    {
        [, $message] = self::exceptionHeadOf($row);

        if ($message === null) {
            return null;
        }

        return preg_replace('/ in .+:\d+$/', '', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedPayload(object $row): array
    {
        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function exceptionHeadOf(object $row): array
    {
        $exception = (string) $row->exception;

        if ($exception === '') {
            return [null, null];
        }

        $firstLine = strstr($exception, "\n", true);
        $firstLine = $firstLine === false ? $exception : $firstLine;

        $parts = explode(': ', $firstLine, 2);

        return [$parts[0] !== '' ? $parts[0] : null, $parts[1] ?? null];
    }
}
