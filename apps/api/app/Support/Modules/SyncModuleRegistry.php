<?php

namespace App\Support\Modules;

use App\Modules\Core\Infrastructure\FeatureFlagCatalogCache;
use App\Support\Authorization\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * ADR-034 §2, §5, §7 (subpaso 0.8.11). Materializa en `modules` y
 * `permissions` lo que declaran los ServiceProvider de los módulos.
 * Idempotente: dos ejecuciones seguidas con el mismo código no producen
 * cambios. Nunca borra — lo que desaparece del código se marca
 * `retired_at`, porque borrar arrastraría por clave foránea las
 * concesiones (`permission_role`) y suscripciones (`module_subscriptions`)
 * históricas de todos los centros.
 *
 * Escribe siempre por `pgsql_owner`: son tablas de referencia con
 * escritura reservada al propietario (REVOKE en las migraciones de 0.8.6
 * y 0.8.7), y este comando es precisamente el único que debe poder
 * tocarlas.
 */
final class SyncModuleRegistry
{
    /**
     * @param  list<class-string>  $providerClasses
     */
    public static function run(array $providerClasses): void
    {
        $providers = array_values(array_filter(
            array_map(static fn (string $class) => new $class(app()), $providerClasses),
            static fn ($provider): bool => $provider instanceof DeclaresModuleRegistry,
        ));

        // `RN-BO-64` (1.6c): las tres validaciones de `depends_on`
        // corren en una pasada previa, sobre el catálogo **completo**
        // declarado, y antes de escribir una sola fila — "aborta el
        // despliegue y no escribe nada" (`CA-BO-034`, `CA-BO-035`,
        // `CA-BO-129`) sólo es cierto si nada se ha escrito todavía
        // cuando se detecta el problema.
        self::validateDependencyGraph($providers);
        self::validateFeatureFlagCatalog($providers);

        $declaredModuleCodes = [];
        $declaredPermissionCodes = [];
        $declaredFlagKeys = [];

        foreach ($providers as $provider) {
            $module = $provider->moduleDescriptor();
            $declaredModuleCodes[] = $module['code'];

            DB::connection('pgsql_owner')->table('modules')->updateOrInsert(
                ['code' => $module['code']],
                ['name_key' => $module['name_key'], 'phase' => $module['phase'], 'retired_at' => null]
            );

            foreach ($provider->declaredPermissions() as $permission) {
                $declaredPermissionCodes[] = $permission['code'];

                DB::connection('pgsql_owner')->table('permissions')->updateOrInsert(
                    ['code' => $permission['code']],
                    [
                        'resource' => $permission['resource'],
                        'action' => $permission['action'],
                        'module_code' => $module['code'],
                        'is_special_category' => $permission['is_special_category'] ?? false,
                        'applicable_scopes' => self::encodeApplicableScopes($permission),
                        'retired_at' => null,
                    ]
                );
            }

            // RN-BO-34, datos.md §9.1 (1.6e): mismo comando, mismo patrón
            // — el catálogo de flags es un tercer bloque de las mismas
            // tres fases, no un comando nuevo.
            foreach ($module['feature_flags'] ?? [] as $flag) {
                $declaredFlagKeys[] = $flag['key'];

                $flagsTable = DB::connection('pgsql_owner')->table('feature_flags');
                $attributes = [
                    'module_code' => $module['code'] === 'core' ? null : $module['code'],
                    'name_key' => $flag['name_key'],
                    'description_key' => $flag['description_key'],
                    'rollout_unit' => $flag['rollout_unit'] ?? 'tenant',
                    'retired_at' => null,
                ];

                if ($flagsTable->where('key', $flag['key'])->exists()) {
                    $flagsTable->where('key', $flag['key'])->update($attributes);
                } else {
                    $flagsTable->insert([
                        'key' => $flag['key'],
                        'public_id' => (string) Str::ulid(),
                        ...$attributes,
                    ]);
                }
            }
        }

        DB::connection('pgsql_owner')->table('modules')
            ->whereNotIn('code', $declaredModuleCodes)
            ->whereNull('retired_at')
            ->update(['retired_at' => now()]);

        DB::connection('pgsql_owner')->table('permissions')
            ->whereNotIn('code', $declaredPermissionCodes)
            ->whereNull('retired_at')
            ->update(['retired_at' => now()]);

        // RN-BO-44, RN-BO-103: retirar un flag también incrementa
        // rules_version — sin esto, un flag retirado en un despliegue
        // seguiría evaluando verdadero hasta que caducara la caché
        // (CA-BO-172). Expresión SQL, nunca leer-y-escribir en PHP
        // (datos.md §9.7).
        $retireQuery = DB::connection('pgsql_owner')->table('feature_flags')->whereNull('retired_at');

        if ($declaredFlagKeys !== []) {
            $retireQuery->whereNotIn('key', $declaredFlagKeys);
        }

        $retireQuery->update([
            'retired_at' => now(),
            'rules_version' => DB::raw('rules_version + 1'),
        ]);

        // OPEN-BO-24 decisión (b): cualquier cambio del catálogo
        // materializado (alta, actualización de descriptor o retirada)
        // invalida la única entrada de caché del evaluador. Referencia
        // por FQCN sin `use`: `App\Support` no depende del namespace
        // interno de un módulo concreto (INV-007) — esta clase resuelve
        // el servicio del contenedor, no importa su implementación.
        app(FeatureFlagCatalogCache::class)->forget();
    }

    /**
     * `ADR-045 §4.5`, `RN-BO-64`. Tres comprobaciones, mismo precedente
     * exacto que `encodeApplicableScopes()`: excepción, sin escribir
     * nada, despliegue detenido.
     *
     * @param  list<DeclaresModuleRegistry>  $providers
     */
    private static function validateDependencyGraph(array $providers): void
    {
        /** @var array<string, array{depends_on: list<string>, essential: bool}> $descriptors */
        $descriptors = [];

        foreach ($providers as $provider) {
            $module = $provider->moduleDescriptor();
            $descriptors[$module['code']] = [
                'depends_on' => $module['depends_on'] ?? [],
                'essential' => $module['essential'] ?? false,
            ];
        }

        // 1. Todo código de `depends_on` existe en el catálogo
        //    **declarado** (CA-BO-034), no en la tabla `modules`, que
        //    puede tener retirados.
        foreach ($descriptors as $code => $descriptor) {
            foreach ($descriptor['depends_on'] as $dependency) {
                if (! isset($descriptors[$dependency])) {
                    throw new InvalidArgumentException(
                        "El módulo «{$code}» declara depends_on «{$dependency}», que no existe en el ".
                        'catálogo declarado (ADR-045 §4.5). platform:sync-registry aborta.'
                    );
                }
            }
        }

        // 2. El grafo no tiene ciclos, y el error nombra el ciclo
        //    (CA-BO-035).
        foreach (array_keys($descriptors) as $code) {
            $cycle = self::findCycle($code, $descriptors, []);

            if ($cycle !== null) {
                throw new InvalidArgumentException(
                    'El grafo de depends_on tiene un ciclo: '.implode(' -> ', $cycle).
                    ' (ADR-045 §4.5). platform:sync-registry aborta.'
                );
            }
        }

        // 3. Un esencial no declara dependencias de módulos no
        //    esenciales (CA-BO-129, RN-BO-64) — derivada, no escrita en
        //    ADR-045: un esencial devuelve `true` sin fila (§4.9) y por
        //    tanto ninguna escritura puede protegerlo si dependiera de
        //    algo descontratable (§4.5).
        foreach ($descriptors as $code => $descriptor) {
            if (! $descriptor['essential']) {
                continue;
            }

            foreach ($descriptor['depends_on'] as $dependency) {
                if (! $descriptors[$dependency]['essential']) {
                    throw new InvalidArgumentException(
                        "El módulo esencial «{$code}» declara depends_on del módulo no esencial ".
                        "«{$dependency}» (RN-BO-64): ninguna escritura podría protegerlo, porque un ".
                        'esencial no tiene fila que bloquear. platform:sync-registry aborta.'
                    );
                }
            }
        }
    }

    /**
     * `RN-BO-34`, `datos.md §9.1`, `CA-BO-089`: dos módulos que declaran
     * la misma clave, o una clave con formato inválido, abortan el
     * despliegue **sin escribir nada** — mismo comportamiento que
     * `validateDependencyGraph()` para `depends_on`, y en la misma pasada
     * previa a cualquier escritura.
     *
     * @param  list<DeclaresModuleRegistry>  $providers
     */
    private static function validateFeatureFlagCatalog(array $providers): void
    {
        $seenKeys = [];

        foreach ($providers as $provider) {
            $module = $provider->moduleDescriptor();

            foreach ($module['feature_flags'] ?? [] as $flag) {
                $key = $flag['key'];

                if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $key) !== 1) {
                    throw new InvalidArgumentException(
                        "El módulo «{$module['code']}» declara el flag «{$key}» con un formato inválido ".
                        '(datos.md §9.1: [a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*). platform:sync-registry aborta.'
                    );
                }

                if (isset($seenKeys[$key])) {
                    throw new InvalidArgumentException(
                        "La clave de flag «{$key}» está declarada por más de un módulo ".
                        "(«{$seenKeys[$key]}» y «{$module['code']}»). platform:sync-registry aborta."
                    );
                }

                $seenKeys[$key] = $module['code'];
            }
        }
    }

    /**
     * @param  array<string, array{depends_on: list<string>, essential: bool}>  $descriptors
     * @param  list<string>  $path
     * @return list<string>|null
     */
    private static function findCycle(string $code, array $descriptors, array $path): ?array
    {
        if (in_array($code, $path, true)) {
            return [...$path, $code];
        }

        $path[] = $code;

        foreach ($descriptors[$code]['depends_on'] ?? [] as $dependency) {
            if (! isset($descriptors[$dependency])) {
                // Código inexistente: ya lo reporta la validación 1, y
                // seguir el rastro aquí produciría un mensaje de ciclo
                // confuso sobre un problema distinto.
                continue;
            }

            $cycle = self::findCycle($dependency, $descriptors, $path);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * REQ-PERM/operacion.md §4.2: valida `applicable_scopes` contra el
     * vocabulario cerrado de `Scope` y **aborta el despliegue** si un
     * módulo declara un ámbito inexistente — preferible un despliegue
     * detenido a un catálogo con un ámbito que ningún `CHECK` de
     * `permission_role` aceptará después.
     *
     * @param  array{code: string, applicable_scopes?: list<string>}  $permission
     */
    private static function encodeApplicableScopes(array $permission): ?string
    {
        if (! isset($permission['applicable_scopes'])) {
            return null;
        }

        foreach ($permission['applicable_scopes'] as $scope) {
            if (Scope::tryFrom($scope) === null) {
                throw new InvalidArgumentException(
                    "El permiso «{$permission['code']}» declara el ámbito «{$scope}», ".
                    'fuera del vocabulario cerrado de Scope (RN-PERM-01). platform:sync-registry aborta.'
                );
            }
        }

        return json_encode($permission['applicable_scopes']);
    }
}
