<?php

namespace App\Support\Api;

/**
 * ADR-038 §6.3: acumulador de errores de validación de negocio (los que no
 * expresa una regla de Laravel: pertenencia a `active_locales`, contraste
 * WCAG, unicidad comprobada en servicio, RPERM-013...). El mensaje se
 * traduce en el momento de añadir el error, porque el idioma de la
 * petición ya está resuelto por ResolveApiLocale antes de que el
 * controlador llegue a validar nada de negocio.
 */
final class ValidationErrorBag
{
    /** @var array<string, list<array{code: string, message: string, params: array<string, mixed>}>> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $params
     */
    public function add(string $field, string $code, string $translationKey, array $params = []): static
    {
        $this->errors[$field][] = [
            'code' => $code,
            'message' => __($translationKey, $params),
            'params' => $params,
        ];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<array{code: string, message: string, params: array<string, mixed>}>>
     */
    public function toArray(): array
    {
        return $this->errors;
    }

    /**
     * @param  array<string, string|int|float>  $detailParams
     *
     * @throws ApiException si se acumuló al menos un error
     */
    public function throwIfAny(?string $detailKey = null, array $detailParams = []): void
    {
        if (! $this->isEmpty()) {
            throw ApiException::validation($this->errors, $detailKey, $detailParams);
        }
    }
}
