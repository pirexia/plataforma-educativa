<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-62` (1.12b): una familia de datos que un
 * módulo ofrece copiar al curso siguiente («Etapas», «Grupos», «Marcos
 * horarios»…).
 *
 * `code` tiene la forma `<moduleCode>.<nombre>` y es único en el registro;
 * `labelKey` es la clave de traducción del módulo dueño; `requires` son los
 * códigos de elementos que deben copiarse con este (también de otros módulos);
 * `createPermission` es el permiso de alta de sus filas, que `curso` exige
 * además del propio de la apertura (`RN-CURSO-78`); `bundle` es `null` o el
 * código de un paquete cuyos elementos no se eligen uno a uno (`RN-CURSO-82`).
 */
final readonly class AcademicYearRolloverElement
{
    /**
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $code,
        public string $labelKey,
        public array $requires,
        public string $createPermission,
        public ?string $bundle = null,
    ) {}

    /** Código del módulo dueño: el prefijo de `code`. */
    public function moduleCode(): string
    {
        return explode('.', $this->code, 2)[0];
    }
}
