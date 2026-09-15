<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Domain\ModuleDescriptor;
use App\Support\Modules\DeclaresModuleRegistry;

/**
 * `RN-BO-63`, funcional.md §5.8.3. El catálogo se resuelve **una sola
 * vez por proceso**, desde los `ServiceProvider` ya registrados por el
 * contenedor (`app()->getProviders()`) — ni escaneo de ficheros
 * (`ModuleServiceProviderDiscovery`, que es lo que usa el comando de
 * consola) ni consulta a `modules`. `isEnabled()` corre en cada petición
 * de cada usuario de cada centro, así que sustituir esto por trabajo de
 * disco o de base de datos sería pagar la limpieza del catálogo con el
 * rendimiento del producto entero (`CA-BO-128`).
 *
 * Registrado como singleton (`CoreServiceProvider`): la memoización vive
 * en la instancia, que dura lo que dura el proceso PHP — igual que
 * `TenantContext`.
 */
final class DeclaredModuleCatalog implements ModuleCatalog
{
    /** @var list<ModuleDescriptor>|null */
    private ?array $descriptors = null;

    public function all(): array
    {
        return $this->resolve();
    }

    public function find(string $code): ?ModuleDescriptor
    {
        foreach ($this->resolve() as $descriptor) {
            if ($descriptor->code === $code) {
                return $descriptor;
            }
        }

        return null;
    }

    public function dependenciesOf(string $code): array
    {
        $seen = [];
        $this->collectDependencies($code, $seen);

        unset($seen[$code]);

        return array_values(array_filter(
            array_keys($seen),
            function (string $candidate): bool {
                $descriptor = $this->find($candidate);

                return $descriptor === null || ! $descriptor->essential;
            },
        ));
    }

    public function dependentsOf(string $code): array
    {
        $dependents = [];

        foreach ($this->resolve() as $descriptor) {
            if ($this->dependsTransitivelyOn($descriptor->code, $code)) {
                $dependents[] = $descriptor->code;
            }
        }

        return $dependents;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectDependencies(string $code, array &$seen): void
    {
        $descriptor = $this->find($code);

        if ($descriptor === null) {
            return;
        }

        foreach ($descriptor->dependsOn as $dependency) {
            if (isset($seen[$dependency])) {
                continue;
            }

            $seen[$dependency] = true;
            $this->collectDependencies($dependency, $seen);
        }
    }

    private function dependsTransitivelyOn(string $code, string $target): bool
    {
        if ($code === $target) {
            return false;
        }

        $seen = [];
        $this->collectDependencies($code, $seen);

        return isset($seen[$target]);
    }

    /**
     * @return list<ModuleDescriptor>
     */
    private function resolve(): array
    {
        if ($this->descriptors !== null) {
            return $this->descriptors;
        }

        $descriptors = [];

        /** @var DeclaresModuleRegistry $provider */
        foreach (app()->getProviders(DeclaresModuleRegistry::class) as $provider) {
            $descriptors[] = ModuleDescriptor::fromArray($provider->moduleDescriptor());
        }

        return $this->descriptors = $descriptors;
    }
}
