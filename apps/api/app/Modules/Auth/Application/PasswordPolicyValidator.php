<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\PasswordPolicy;
use App\Support\Api\ValidationErrorBag;

/**
 * RN-AUTH-01/RN-AUTH-02, ADR-038 §6.3. Traduce los códigos de
 * `PasswordPolicy::violations()` a `auth.validation.password.<code>`,
 * usando `ValidationErrorBag` (no una clase `Illuminate\Contracts\
 * Validation\ValidationRule`, ver issue #60: `ValidationErrorFormatter`
 * antepone "core." al `code` derivado de cualquier clase de regla, sin
 * importar el módulo — con `ValidationErrorBag` el `code` lo fija esta
 * clase, completo y correcto).
 */
final class PasswordPolicyValidator
{
    public function __construct(
        private readonly PasswordPolicy $policy,
    ) {}

    public function validate(string $field, string $password, ValidationErrorBag $errors): void
    {
        foreach ($this->policy->violations($password) as $code) {
            $params = $code === 'min_length' ? ['min' => (int) config('auth-local.password_min_length')] : [];

            $errors->add($field, "auth.validation.password.{$code}", "auth.validation.password.{$code}", $params);
        }
    }
}
