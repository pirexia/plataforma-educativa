<?php

namespace App\Modules\Acad\Application;

use App\Support\Api\ApiException;

/**
 * REQ-ACAD/api.md §5-§6: errores de la estructura académica. El `code` de
 * api.md §6 es a la vez la clave de su mensaje en `lang/*\/acad.php`.
 */
final class StructureErrors
{
    /** Orden de los nombres de dependientes en el mensaje de `has_dependents`. */
    private const DEPENDENT_KEYS = ['courses', 'academic_periods', 'groups', 'subgroups', 'subjects'];

    /**
     * `RN-ACAD-20`, api.md §5: `409 acad.conflict.has_dependents`, con
     * `params.dependents` (nombres técnicos) en `errors.dependents[0]`.
     *
     * @param  list<string>  $dependents  de `courses`, `academic_periods`, `groups`, `subgroups`, `subjects`
     */
    public static function hasDependents(string $entity, string $code, array $dependents): ApiException
    {
        $names = implode(', ', array_map(static fn (string $d): string => __("acad.dependents.{$d}"), $dependents));
        $messageParams = ['entity' => __("acad.entities.{$entity}"), 'code' => $code, 'dependents' => $names];
        $key = 'acad.conflict.has_dependents';

        return ApiException::conflict($key, $messageParams, [
            'dependents' => [['code' => $key, 'message' => __($key, $messageParams), 'params' => ['dependents' => $dependents]]],
        ]);
    }

    /** `422` con un único código `acad.validation.*` en `$field`. */
    public static function invalid(string $field, string $code): ApiException
    {
        return ApiException::validation([$field => [['code' => $code, 'message' => __($code), 'params' => []]]]);
    }

    /**
     * `422` con un código `acad.validation.*` que lleva `params` (y los usa en
     * su mensaje), en `$field`.
     *
     * @param  array<string, scalar>  $params
     */
    public static function invalidWith(string $field, string $code, array $params): ApiException
    {
        return ApiException::validation([$field => [['code' => $code, 'message' => __($code, $params), 'params' => $params]]]);
    }

    /** `RN-ACAD-01`: `422 acad.validation.code_taken` en el campo `code`. */
    public static function codeTaken(): ApiException
    {
        return self::invalid('code', 'acad.validation.code_taken');
    }

    /** `404 curso.no_active_year` (mismo cuerpo que el que produce `curso`). */
    public static function noActiveYear(): ApiException
    {
        $key = 'curso.no_active_year';

        return ApiException::notFound($key, [], [
            'academic_year' => [['code' => $key, 'message' => __($key), 'params' => []]],
        ]);
    }

    /**
     * @param  list<string>  $unknown
     * @return list<string>
     */
    public static function orderedDependents(array $unknown): array
    {
        return array_values(array_filter(self::DEPENDENT_KEYS, static fn (string $k): bool => in_array($k, $unknown, true)));
    }
}
