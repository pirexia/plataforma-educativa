<?php

namespace App\Support\Modules\Scaffolding;

/**
 * ADR-056 §3.5: el resultado, ya calculado y validado, de `ModuleScaffolder`:
 * ficheros nuevos y contenido final de los ficheros compartidos que se editan.
 * Rutas relativas a la raíz de la aplicación (`apps/api`).
 */
final readonly class ScaffoldPlan
{
    /**
     * @param  array<string, string>  $creates  ruta => contenido
     * @param  array<string, string>  $edits  ruta => contenido final (solo los que cambian)
     */
    public function __construct(
        public array $creates,
        public array $edits,
    ) {}
}
