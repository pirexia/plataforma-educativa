<?php

namespace App\Modules\Prl\Application;

use App\Support\Api\ApiException;

/**
 * REQ-PRL/api.md §8.4, §8.6: errores del módulo. El `code` de cada error es a la vez la clave de su mensaje en
 * `lang/*\/prl.php` (`prl.<grupo>.<código>`, `INV-009`).
 */
final class PrlErrors
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

    /**
     * `RN-PRL-02`/`CA-PRL-002`: **un solo** error, con un solo cuerpo, para «la persona no figura», «la persona no existe» y
     * «la persona está excluida por revocación» (`OPEN-FAM-UNIT-34`): la puerta no revela un dato judicial a conserjería ni
     * distingue una persona del centro de una que no existe.
     */
    public static function personNotAuthorized(): ApiException
    {
        return self::invalid('picked_up_by', 'prl.movement.person_not_authorized');
    }
}
