<?php

namespace App\Modules\Alum\Application;

use App\Support\Api\ApiException;

/**
 * REQ-ALUM/api.md §1: errores del módulo. El `code` de cada error es a la vez la clave de su mensaje en
 * `lang/*\/alum.php` (`alum.<grupo>.<código>`).
 */
final class AlumErrors
{
    /**
     * `422` con un único código en `$field`.
     *
     * @param  array<string, scalar>  $params
     */
    public static function invalid(string $field, string $code, array $params = []): ApiException
    {
        return ApiException::validation([$field => [['code' => $code, 'message' => __($code, $params), 'params' => $params]]]);
    }

    /** `409` con el código también en `errors.<campo>` para que el cliente lo encuentre sin analizar texto. */
    public static function conflict(string $field, string $code): ApiException
    {
        return ApiException::conflict($code, [], [$field => [['code' => $code, 'message' => __($code), 'params' => []]]]);
    }

    /** `403` por usar un filtro que exige un permiso que el sujeto no tiene (`include_deleted`, `api.md §1`). */
    public static function filterForbidden(): ApiException
    {
        return ApiException::forbidden('alum.validation.filter_forbidden');
    }
}
