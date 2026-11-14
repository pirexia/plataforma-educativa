<?php

namespace App\Modules\Core\Domain;

use RuntimeException;

/**
 * ADR-068 §5.2: `PersonRegistrar::correctUnaccounted()` no actúa sobre esa persona: no existe en el centro, está
 * borrada o **tiene usuario** (se corrige por `/users`). El mensaje no distingue los casos: quien llama responde `404`
 * sin revelar que la persona existe ni que tiene cuenta.
 */
final class PersonNotCorrectableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La persona no se puede corregir por esta vía.');
    }
}
