<?php

namespace App\Http\Requests;

use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorFormatter;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-038 §6: base de toda petición validada de la API. Un fallo de
 * validación de forma (reglas de Laravel) se traduce a `application/
 * problem+json` con la forma exacta de §6.3, nunca al redirect/422 plano
 * por defecto de Laravel.
 */
abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(ValidatorContract $validator): never
    {
        throw ApiException::validation(ValidationErrorFormatter::fromValidator($validator));
    }

    protected function failedAuthorization(): never
    {
        throw ApiException::forbidden();
    }
}
