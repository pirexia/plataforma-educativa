<?php

namespace Tests\Support;

/**
 * ADR-056 §3.3: enumeración de los módulos de `app/Modules/` por sistema de
 * ficheros, para que las reglas de arquitectura vigilen un módulo nuevo sin
 * tocar los tests. Usa `__DIR__` y no `base_path()` porque Pest carga los
 * ficheros de test (y genera sus `arch()`) antes de arrancar la aplicación.
 */
final class ArchitectureModules
{
    /**
     * Nombres de directorio (StudlyCase) de cada módulo, ordenados.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []);
        sort($names);

        return array_values($names);
    }
}
