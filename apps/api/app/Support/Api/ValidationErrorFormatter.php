<?php

namespace App\Support\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

/**
 * ADR-038 §6.3: convierte un `Illuminate\Validation\Validator` fallido a la
 * forma {code, message, params} exigida por el formato de error. `code` se
 * deriva del nombre de la regla de Laravel (estable entre idiomas: no
 * depende del texto traducido); `message` es el texto ya traducido que el
 * propio Validator resolvió desde lang/{locale}/validation.php; `params`
 * son los parámetros de la regla, normalizados con nombre.
 *
 * Tipado por el contrato (`Illuminate\Contracts\Validation\Validator`), no
 * por la clase concreta: ambos puntos de llamada (`ApiFormRequest::
 * failedValidation()`, `ProblemResponseFactory::classify()` sobre
 * `ValidationException::$validator`) ya reciben el contrato de Laravel, y
 * este método solo usa `failed()`/`errors()`, ambos declarados en él —
 * exigir la clase concreta era una restricción de tipo innecesaria
 * (hallazgo de `phpstan analyse`, issue #51).
 */
final class ValidationErrorFormatter
{
    /**
     * @return array<string, list<array{code: string, message: string, params: array<string, mixed>}>>
     */
    public static function fromValidator(Validator $validator): array
    {
        $errors = [];

        foreach ($validator->failed() as $field => $rules) {
            $messages = $validator->errors()->get($field);
            $index = 0;

            foreach ($rules as $rule => $ruleParams) {
                $ruleName = str_contains($rule, '\\') ? class_basename($rule) : $rule;
                $code = 'core.validation.'.Str::snake($ruleName);
                $message = $messages[$index] ?? $messages[0] ?? $code;

                $errors[$field][] = [
                    'code' => $code,
                    'message' => $message,
                    'params' => self::normalizeParams($rule, $ruleParams),
                ];

                $index++;
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $ruleParams
     * @return array<string, mixed>
     */
    private static function normalizeParams(string $rule, array $ruleParams): array
    {
        return match (strtolower($rule)) {
            'max', 'min' => ['limit' => $ruleParams[0] ?? null],
            'in', 'notin' => ['values' => $ruleParams],
            'date_format' => ['format' => $ruleParams[0] ?? null],
            'exists', 'unique' => ['table' => $ruleParams[0] ?? null],
            default => [],
        };
    }
}
