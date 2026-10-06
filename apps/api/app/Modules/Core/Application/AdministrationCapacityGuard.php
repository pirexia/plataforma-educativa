<?php

namespace App\Modules\Core\Application;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2.1 (`RN-PERM-47`), api.md §14.5: el centro
 * nunca pierde la capacidad completa de administración.
 *
 * **Conjunto A**: `ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS`
 * (la lista única que aprovisiona `administrador_centro`), restringido a
 * los códigos no retirados del catálogo y de módulos utilizables por el
 * tenant. La regla no depende de ningún código de rol (`RN-PERM-46`).
 *
 * **El centro cumple** si existe al menos un usuario vivo y `activo` que
 * posee de forma efectiva (con `deny`, inercias y todo) todos los códigos
 * de A con ámbito `todos`. El cálculo usa el mismo `PermissionResolver`
 * que autoriza las peticiones (`RN-PERM-22`), con una instancia nueva por
 * evaluación: el resolutor registrado como `scoped()` memoiza las
 * concesiones por sujeto y devolvería el estado anterior a la escritura.
 *
 * **Cuándo rechaza**: solo si el centro cumplía antes y no cumpliría
 * después. Un centro que ya no cumplía (p. ej. administrador todavía
 * `pendiente`) no ve rechazada ninguna escritura: rechazarlas impediría
 * justo las que lo reparan.
 *
 * **Concurrencia**: la comprobación y la escritura se serializan por
 * tenant en la misma transacción con un bloqueo de transacción de
 * PostgreSQL (`pg_advisory_xact_lock`), sin tabla ni columna; se libera
 * al terminar la transacción. Dos escrituras que por separado cumplen
 * pero juntas no, se ejecutan una detrás de otra: la segunda ve el estado
 * de la primera (READ COMMITTED, una instantánea nueva por sentencia).
 *
 * **`RN-CORE-07` bajo el mismo bloqueo** (issue #349): las rutas que
 * llaman a `protect()` repiten dentro de `$write` la comprobación de
 * `SchoolAdministratorGuard` sobre lecturas frescas; el bloqueo serializa
 * ambas reglas por tenant.
 *
 * **Cálculo del «después»**: la escritura se ejecuta dentro de la
 * transacción y se evalúa su resultado; si el centro dejaría de cumplir,
 * la excepción deshace la transacción entera (`409`, nada se guarda, ni
 * la escritura ni sus filas de auditoría). Por eso el cierre `$write` no
 * debe emitir eventos ni efectos externos: los emite quien llama, después.
 *
 * **Candidatos acotados**: quien posea todo A posee, en particular, el
 * primer código de A con `allow` y ámbito `todos` en alguno de sus roles;
 * solo se evalúan los usuarios activos con esa concesión. Es una condición
 * necesaria, no una aproximación: el resultado es el de evaluarlos a todos.
 *
 * **`codes` del `409`**: unión, ordenada, de los códigos de A que no posee
 * (con `todos`, efectivo) cada usuario que **sí** cumplía antes; un usuario
 * que ya no es candidato (baja o desactivación) cuenta como no poseer
 * ninguno. Son los códigos que dejarían de tener titular completo.
 */
final class AdministrationCapacityGuard
{
    /** Primer argumento del bloqueo de dos enteros: identifica esta regla. */
    private const LOCK_RULE_KEY = 47_001_500;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ScopeResolverRegistry $scopeResolvers,
        private readonly ModuleAvailability $moduleAvailability,
    ) {}

    /**
     * Ejecuta `$write` en una transacción serializada por tenant y la
     * deshace con `409` si la escritura hace pasar al centro de cumplir a
     * no cumplir.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function protect(Closure $write): mixed
    {
        return DB::transaction(function () use ($write) {
            $this->lockTenant();

            $required = $this->requiredCodes();
            $before = $required === [] ? [] : $this->compliantUserIds($required);

            if ($before === []) {
                // No cumplía antes (o no hay nada que proteger): no se rechaza.
                return $write();
            }

            $result = $write();

            $this->assertStillComplies($required, $before);

            return $result;
        });
    }

    private function lockTenant(): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [
            self::LOCK_RULE_KEY,
            $this->tenantContext->tenantId() % 2_147_483_647,
        ]);
    }

    /**
     * @return list<string> los códigos de A, ordenados
     */
    private function requiredCodes(): array
    {
        $codes = Permission::query()
            ->whereNull('retired_at')
            ->whereIn('code', ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS)
            ->get(['code', 'module_code'])
            ->filter(fn (Permission $permission): bool => $this->moduleAvailability->isEnabled($permission->module_code))
            ->pluck('code')
            ->all();

        sort($codes);

        return $codes;
    }

    /**
     * @param  list<string>  $required
     * @return list<int> ids de los usuarios vivos y activos con todo A
     */
    private function compliantUserIds(array $required): array
    {
        $anchor = $required[0];

        $candidates = User::query()
            ->where('status', UserStatus::Activo)
            ->whereHas('roles', fn ($roles) => $roles->whereHas('permissionGrants', fn ($grants) => $grants
                ->where('permission_code', $anchor)
                ->where('effect', 'allow')
                ->where('scope', 'todos')))
            ->get();

        $compliant = [];

        foreach ($candidates as $candidate) {
            if ($this->missingCodes($candidate, $required) === []) {
                $compliant[] = $candidate->id;
            }
        }

        return $compliant;
    }

    /**
     * @param  list<string>  $required
     * @return list<string> los códigos de A que el usuario no posee con `todos`
     */
    private function missingCodes(User $user, array $required): array
    {
        $resolver = new PermissionResolver($this->scopeResolvers, $this->moduleAvailability);

        return array_values(array_filter($required, function (string $code) use ($resolver, $user): bool {
            $decision = $resolver->decide($user, $code);

            return ! ($decision->permitted && $decision->isUnrestricted());
        }));
    }

    /**
     * @param  list<string>  $required
     * @param  list<int>  $before
     */
    private function assertStillComplies(array $required, array $before): void
    {
        $missing = [];

        foreach ($before as $userId) {
            $user = User::query()->where('status', UserStatus::Activo)->find($userId);

            if ($user === null) {
                // Dado de baja o desactivado por la propia escritura.
                $missing = [...$missing, ...$required];

                continue;
            }

            $userMissing = $this->missingCodes($user, $required);

            if ($userMissing === []) {
                return;
            }

            $missing = [...$missing, ...$userMissing];
        }

        $codes = array_values(array_unique($missing));
        sort($codes);

        $detailParams = ['codes' => implode(', ', $codes)];

        throw ApiException::conflict('core.validation.administration_capacity_lost', $detailParams, [
            'administration_capacity' => [[
                'code' => 'core.validation.administration_capacity_lost',
                'message' => __('core.validation.administration_capacity_lost', $detailParams),
                'params' => ['codes' => $codes],
            ]],
        ]);
    }
}
