<?php

namespace App\Modules\Curso\Application;

use App\Support\Api\ApiException;

/**
 * REQ-CURSO/api.md §5 (1.10): constructor único de los errores del módulo.
 * Los códigos `curso.*` van completos (nunca derivados por el
 * `ValidationErrorFormatter`, que antepone `core.`: issue #60). `message`
 * sale de `lang/{es,en,de,fr}/curso.php` en el idioma resuelto, con la misma clave que
 * el `code`.
 */
final class CursoErrors
{
    /**
     * Un único error de campo `{code, message, params}` (ADR-038 §6.3).
     *
     * @param  array<string, mixed>  $params
     * @return array{code: string, message: string, params: array<string, mixed>}
     */
    public static function item(string $code, array $params = []): array
    {
        return ['code' => $code, 'message' => __($code, $params), 'params' => $params];
    }

    /**
     * 422 con un error por campo.
     *
     * @param  array<string, list<array{code: string, message: string, params: array<string, mixed>}>>  $errors
     */
    public static function validation(array $errors): ApiException
    {
        return ApiException::validation($errors);
    }

    /**
     * 409 de estado del recurso: `errors.academic_year[0]` y `detail` con el
     * mismo texto.
     *
     * @param  array<string, string|int|float>  $params
     */
    public static function conflict(string $code, array $params = []): ApiException
    {
        return ApiException::conflict($code, $params, ['academic_year' => [self::item($code, $params)]]);
    }

    /** 404 `curso.no_active_year` (`GET /academic-years/current` y lecturas por omisión). */
    public static function noActiveYear(): ApiException
    {
        return ApiException::notFound('curso.no_active_year', [], [
            'academic_year' => [self::item('curso.no_active_year')],
        ]);
    }
}
