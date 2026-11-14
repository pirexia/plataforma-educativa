<?php

namespace App\Modules\Backoffice\Application;

use App\Support\Api\ApiException;

/**
 * ADR-038 §6.3, api.md §5. Construye el `422` de un código de error
 * propio de `REQ-BO` en la forma exacta que exige el catálogo cerrado de
 * `errors` — mismo patrón que `PlatformCursorCodec::invalid()`, aquí
 * reutilizable por cualquier servicio de este módulo en vez de repetir
 * el mismo bloque en cada uno.
 */
final class BoValidationError
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function forField(string $field, string $code, array $params = []): ApiException
    {
        return ApiException::validation([
            $field => [[
                'code' => $code,
                'message' => __($code, $params),
                'params' => $params,
            ]],
        ]);
    }
}
