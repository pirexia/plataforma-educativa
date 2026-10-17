<?php

namespace App\Support\Modules\Scaffolding;

use RuntimeException;

/**
 * ADR-056 §3.5, CA-056-30: el generador falla ANTES de escribir nada. Lleva
 * cada error como clave de traducción más sus parámetros (INV-009).
 */
final class ModuleScaffoldException extends RuntimeException
{
    /**
     * @param  list<array{0: string, 1: array<string, string>}>  $errors  clave de `make-module.errors.*` y parámetros
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(', ', array_map(static fn (array $e): string => $e[0], $errors)));
    }
}
