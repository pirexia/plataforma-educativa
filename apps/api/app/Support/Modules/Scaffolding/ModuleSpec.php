<?php

namespace App\Support\Modules\Scaffolding;

/**
 * ADR-056 §3.5: lo que el usuario pide a `php artisan make:module`, sin validar
 * (la validación es de `ModuleScaffolder::validate()`, antes de escribir nada).
 */
final readonly class ModuleSpec
{
    /** Idiomas obligatorios (ADR-021), en el orden en que se escriben. */
    public const LOCALES = ['es', 'en', 'de', 'fr'];

    /** Políticas de auditoría de ADR-035 §2. */
    public const AUDIT_POLICIES = ['full', 'selective', 'redacted'];

    /**
     * @param  array<string, string|null>  $names  idioma (`es`, `en`, `de`, `fr`) => nombre del módulo
     */
    public function __construct(
        public string $name,
        public ?string $code,
        public array $names,
        public ?string $entity,
        public ?string $auditPolicy,
    ) {}
}
