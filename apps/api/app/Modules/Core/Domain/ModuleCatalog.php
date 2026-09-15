<?php

namespace App\Modules\Core\Domain;

/**
 * `ADR-045 §4.5`, funcional.md §5.8.2, `OPEN-BO-18`. Lectura del catálogo
 * de descriptores declarados en código — la consume también
 * `App\Support\Modules\ModuleAvailability` (a través de su
 * implementación en `Core\Infrastructure`) y `REQ-BO` (1.6c) para
 * resolver el cierre de dependencias de la contratación de módulos.
 *
 * `RN-BO-63`: el catálogo se resuelve **una sola vez por proceso**, desde
 * los `ServiceProvider` ya registrados por el contenedor — nunca por
 * escaneo de ficheros ni por consulta a `modules` en el camino de
 * petición. Es responsabilidad de la implementación, no de esta
 * interfaz.
 */
interface ModuleCatalog
{
    /**
     * @return list<ModuleDescriptor>
     */
    public function all(): array;

    public function find(string $code): ?ModuleDescriptor;

    /**
     * Cierre transitivo hacia abajo, sin el propio `$code` y sin
     * esenciales: un esencial no necesita fila en `module_subscriptions`
     * (`RN-BO-65`) y por tanto nunca entra en un cierre de dependencias.
     *
     * @return list<string>
     */
    public function dependenciesOf(string $code): array;

    /**
     * Módulos declarados que dependen, transitivamente, de `$code`.
     *
     * @return list<string>
     */
    public function dependentsOf(string $code): array;
}
