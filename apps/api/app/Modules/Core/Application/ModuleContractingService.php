<?php

namespace App\Modules\Core\Application;

use App\Models\Module;
use App\Models\ModuleSubscription;
use App\Modules\Core\Domain\Events\ModuleContracted;
use App\Modules\Core\Domain\Events\ModuleDecontracted;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Domain\ModuleChange;
use App\Modules\Core\Domain\ModuleContracting;
use App\Modules\Core\Domain\ModuleContractingOutcome;
use App\Modules\Core\Domain\ModuleContractingPreview;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `ADR-045 §4.5`, `§4.8`; funcional.md §5.8. Implementación única del
 * cierre de dependencias (`RN-BO-22`), consumida por la contratación
 * individual, la masiva y la vista previa de `REQ-BO` (`OPEN-BO-18`).
 *
 * No es un adaptador `Eloquent*` de persistencia (por eso vive en
 * `Core\Application` y no en `Core\Infrastructure`): orquesta una
 * transacción ajena, un bloqueo y las reglas de `ADR-045`, mismo criterio
 * de asimetría que `ProvisionTenantDefaults` (`ADR-048 §4.4`).
 */
final class ModuleContractingService implements ModuleContracting
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
    ) {}

    public function preview(int $tenantId, ModuleChange $change): ModuleContractingPreview
    {
        $subscribed = $this->subscriptionMap($tenantId);
        $closure = $this->computeClosure($change, $subscribed);

        $modulesToContract = [];
        $modulesToDecontract = [];
        $cascaded = [];

        foreach ($closure['apply'] as $item) {
            if ($item['enabled']) {
                $modulesToContract[] = $item['module_code'];
            } else {
                $modulesToDecontract[] = $item['module_code'];
            }

            if ($item['cascaded']) {
                $cascaded[] = $item['module_code'];
            }
        }

        // RN-BO-67: sin `cascade`, la vista previa igualmente anuncia
        // qué se arrastraría, para que el operador decida.
        if ($closure['apply'] === [] && $closure['missing'] !== []) {
            $target = array_merge([$change->moduleCode], $closure['missing']);

            if ($change->enabled) {
                $modulesToContract = $target;
            } else {
                $modulesToDecontract = $target;
            }

            $cascaded = $closure['missing'];
        }

        $affectedModules = array_values(array_unique(array_merge($modulesToContract, $modulesToDecontract)));

        return new ModuleContractingPreview(
            affectedTenants: $affectedModules === [] && $closure['blocked'] === [] ? 0 : 1,
            modulesToContract: array_values(array_unique($modulesToContract)),
            modulesToDecontract: array_values(array_unique($modulesToDecontract)),
            cascadedDependencies: array_values(array_unique($cascaded)),
            blocked: $closure['blocked'],
            impact: [
                'users_affected' => $this->usersAffected($tenantId, $affectedModules),
                // api.md §2.7: ningún módulo declara estas dos en 1.6c,
                // así que van siempre vacías y nunca rellenas a mano.
                'screens_removed' => [],
                'integrations_disabled' => [],
            ],
        );
    }

    /**
     * `RN-BO-66`: el llamador debe tener ya abierta una transacción sobre
     * `pgsql_platform` (mismo patrón que
     * `TenantLifecycleService::executeApprovedDeletion()`), para que la
     * escritura de `admin_action_logs` que hace `REQ-BO` sea atómica con
     * ésta.
     */
    public function apply(int $tenantId, ModuleChange $change): ModuleContractingOutcome
    {
        // El punto de serialización es la fila de `tenants`, no las de
        // `module_subscriptions`: contratar crea filas que todavía no
        // existen y `SELECT … FOR UPDATE` no bloquea lo que no está
        // (funcional.md §5.8.5, datos.md §7.6).
        // `withTrashed()` (RN-BO-71): un tenant `eliminado` está borrado
        // lógicamente y el scope por defecto lo excluiría de
        // `findOrFail()`, lanzando `ModelNotFoundException` en vez de
        // llegar a la comprobación de estado de abajo — dejando
        // `eliminado` sin la respuesta `409 bo.module.tenant_state_invalid`
        // que RN-BO-71 exige explícitamente para ese estado.
        $tenant = Tenant::withTrashed()->lockForUpdate()->findOrFail($tenantId);

        if (! in_array($tenant->status, [TenantStatus::Activo, TenantStatus::Suspendido, TenantStatus::EnBaja], true)) {
            throw ApiException::conflict('bo.module.tenant_state_invalid', ['tenant_status' => $tenant->status->value]);
        }

        $subscribed = $this->subscriptionMap($tenantId);
        $closure = $this->computeClosure($change, $subscribed);

        if ($closure['blocked'] !== []) {
            $first = $closure['blocked'][0];

            throw ApiException::validation([
                'module_code' => [[
                    'code' => $first['reason_code'],
                    'message' => __($first['reason_code'], ['module_code' => $first['module_code']]),
                    'params' => ['module_code' => $first['module_code']],
                ]],
            ]);
        }

        if ($closure['apply'] === [] && $closure['missing'] !== [] && ! $change->cascade) {
            $errorCode = $change->enabled ? 'bo.module.missing_dependencies' : 'bo.module.dependent_modules';

            throw ApiException::conflict($errorCode, ['modules' => implode(', ', $closure['missing'])]);
        }

        if ($closure['apply'] === []) {
            // RN-BO-69: contratar lo ya contratado (o descontratar lo ya
            // descontratado) es no-operación completa, `reason` incluido.
            return new ModuleContractingOutcome($tenantId, [], [$change->moduleCode]);
        }

        $now = now();
        $applied = [];

        // AuditActor::actingAs('console', …): mismo patrón defensivo que
        // CloneTenant/ProvisionTenantDefaults para una escritura de
        // tenant disparada por un administrador de plataforma, que no es
        // un usuario de este centro (RN-BO-73).
        AuditActor::actingAs('console', function () use ($tenantId, $change, $closure, $now, &$applied): void {
            foreach ($closure['apply'] as $item) {
                // `forceFill()`, no `updateOrCreate()` (datos.md §7.6,
                // RN-BO-73): `tenant_id` no está en `$fillable` a
                // propósito, así que el array de atributos de
                // `updateOrCreate()` lo descarta en el `INSERT` — mismo
                // motivo exacto por el que `ProvisionTenantDefaults` usa
                // `forceCreate()` en vez de `create()`. En modo
                // plataforma, `BelongsToTenant` tampoco lo rellena solo
                // (docblock de esa clase): hay que fijarlo a mano.
                $subscription = ModuleSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->where('module_code', $item['module_code'])
                    ->first() ?? new ModuleSubscription;

                $subscription->forceFill([
                    'tenant_id' => $tenantId,
                    'module_code' => $item['module_code'],
                    'enabled' => $item['enabled'],
                    'enabled_at' => $item['enabled'] ? $now : null,
                    'disabled_at' => $item['enabled'] ? null : $now,
                    // RN-BO-72: literal en todas las filas tocadas,
                    // arrastradas incluidas — nunca una frase compuesta.
                    'reason' => $change->reason,
                ])->save();

                $applied[] = [
                    'module_code' => $item['module_code'],
                    'enabled' => $item['enabled'],
                    'cascaded' => $item['cascaded'],
                    'occurred_at' => $now,
                ];
            }
        });

        return new ModuleContractingOutcome($tenantId, $applied, []);
    }

    /**
     * `RN-BO-75`: se llama fuera del bloque de plataforma y después del
     * `COMMIT` de `apply()`. Si un ítem falla, se intenta igual con el
     * resto y se relanza al final (`RN-BO-76`: el llamador decide qué
     * hacer con el fallo — no dejar que llegue al cliente como `5xx`).
     */
    public function publish(ModuleContractingOutcome $outcome): void
    {
        $failure = null;
        $actorId = Auth::guard('platform')->id();

        foreach ($outcome->applied as $item) {
            try {
                app(TenantContext::class)->runFor(
                    $outcome->tenantId,
                    static fn () => Cache::forget("modules:{$item['module_code']}:enabled"),
                );
            } catch (Throwable $e) {
                $failure ??= $e;
            }

            try {
                event($item['enabled']
                    ? new ModuleContracted($outcome->tenantId, $item['module_code'], $actorId)
                    : new ModuleDecontracted($outcome->tenantId, $item['module_code'], $actorId));
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param  array<string, bool>  $subscribed
     * @return array{apply: list<array{module_code: string, enabled: bool, cascaded: bool}>, blocked: list<array{module_code: string, reason_code: string}>, missing: list<string>}
     */
    private function computeClosure(ModuleChange $change, array $subscribed): array
    {
        $descriptor = $this->catalog->find($change->moduleCode);

        if ($descriptor === null) {
            return ['apply' => [], 'blocked' => [], 'missing' => []];
        }

        // RN-BO-65: la celda está bloqueada en las dos direcciones,
        // ninguna capacidad ni cascade la salta.
        if ($descriptor->essential) {
            return ['apply' => [], 'blocked' => [['module_code' => $change->moduleCode, 'reason_code' => 'bo.module.essential']], 'missing' => []];
        }

        $currentlyEnabled = $subscribed[$change->moduleCode] ?? false;

        // RN-BO-69, antes que el bloqueo de retirado a propósito:
        // reconfirmar con `enabled: true` un módulo que ya estaba
        // contratado y que después se retiró es una no-operación
        // completa (nada que escribir), no un intento nuevo de
        // contratación — bloquearla sorprendería a un reintento
        // idempotente de un cuerpo que antes de retirarse respondía 200.
        if ($currentlyEnabled === $change->enabled) {
            return ['apply' => [], 'blocked' => [], 'missing' => []];
        }

        // RN-BO-70: un retirado no se contrata de nuevas, ni directamente
        // ni por arrastre — sí se descontrata. Sólo se llega aquí para
        // una transición de estado real (el no-op ya se resolvió arriba).
        if ($change->enabled && $this->retiredAt($change->moduleCode) !== null) {
            return ['apply' => [], 'blocked' => [['module_code' => $change->moduleCode, 'reason_code' => 'bo.module.retired']], 'missing' => []];
        }

        if ($change->enabled) {
            return $this->closureForContract($change, $subscribed);
        }

        return $this->closureForDecontract($change, $subscribed);
    }

    /**
     * @param  array<string, bool>  $subscribed
     * @return array{apply: list<array{module_code: string, enabled: bool, cascaded: bool}>, blocked: list<array{module_code: string, reason_code: string}>, missing: list<string>}
     */
    private function closureForContract(ModuleChange $change, array $subscribed): array
    {
        $needed = array_values(array_filter(
            $this->catalog->dependenciesOf($change->moduleCode),
            fn (string $dependency): bool => ! ($subscribed[$dependency] ?? false),
        ));

        $blocked = [];

        foreach ($needed as $dependency) {
            if ($this->retiredAt($dependency) !== null) {
                $blocked[] = ['module_code' => $dependency, 'reason_code' => 'bo.module.retired'];
            }
        }

        if ($blocked !== []) {
            return ['apply' => [], 'blocked' => $blocked, 'missing' => $needed];
        }

        if ($needed !== [] && ! $change->cascade) {
            return ['apply' => [], 'blocked' => [], 'missing' => $needed];
        }

        $apply = [['module_code' => $change->moduleCode, 'enabled' => true, 'cascaded' => false]];

        foreach ($needed as $dependency) {
            $apply[] = ['module_code' => $dependency, 'enabled' => true, 'cascaded' => true];
        }

        return ['apply' => $apply, 'blocked' => [], 'missing' => $needed];
    }

    /**
     * @param  array<string, bool>  $subscribed
     * @return array{apply: list<array{module_code: string, enabled: bool, cascaded: bool}>, blocked: list<array{module_code: string, reason_code: string}>, missing: list<string>}
     */
    private function closureForDecontract(ModuleChange $change, array $subscribed): array
    {
        $dependents = array_values(array_filter(
            $this->catalog->dependentsOf($change->moduleCode),
            fn (string $dependent): bool => $subscribed[$dependent] ?? false,
        ));

        if ($dependents !== [] && ! $change->cascade) {
            return ['apply' => [], 'blocked' => [], 'missing' => $dependents];
        }

        $apply = [['module_code' => $change->moduleCode, 'enabled' => false, 'cascaded' => false]];

        foreach ($dependents as $dependent) {
            $apply[] = ['module_code' => $dependent, 'enabled' => false, 'cascaded' => true];
        }

        return ['apply' => $apply, 'blocked' => [], 'missing' => $dependents];
    }

    /**
     * @return array<string, bool>
     */
    private function subscriptionMap(int $tenantId): array
    {
        return ModuleSubscription::query()
            ->where('tenant_id', $tenantId)
            ->pluck('enabled', 'module_code')
            ->all();
    }

    private function retiredAt(string $code): ?Carbon
    {
        return Module::query()->where('code', $code)->first()?->retired_at;
    }

    /**
     * api.md §2.7: "en 1.6 son los usuarios del tenant con algún permiso
     * del módulo". Consulta directa por `pgsql_platform` con `tenant_id`
     * a mano (`TenantScope` no filtra en modo plataforma): la vista
     * previa corre siempre dentro de `runAsPlatform`, sin contexto de
     * tenant al que RLS pudiera anclarse.
     *
     * @param  list<string>  $moduleCodes
     */
    private function usersAffected(int $tenantId, array $moduleCodes): int
    {
        if ($moduleCodes === []) {
            return 0;
        }

        return (int) DB::connection('pgsql_platform')->table('role_user')
            ->join('permission_role', function ($join): void {
                $join->on('permission_role.role_id', '=', 'role_user.role_id')
                    ->on('permission_role.tenant_id', '=', 'role_user.tenant_id');
            })
            ->join('permissions', 'permissions.code', '=', 'permission_role.permission_code')
            ->where('role_user.tenant_id', $tenantId)
            ->where('permission_role.effect', 'allow')
            ->whereIn('permissions.module_code', $moduleCodes)
            ->distinct()
            ->count('role_user.user_id');
    }
}
