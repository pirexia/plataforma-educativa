<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\FeatureFlagAdministration;
use App\Modules\Core\Domain\FeatureFlagDecisionEngine;
use App\Modules\Core\Domain\Models\FeatureFlag;
use App\Modules\Core\Domain\Models\FeatureFlagRule;
use App\Support\Api\ApiException;
use App\Support\FeatureFlags\FeatureFlagRolloutUnit;
use App\Support\FeatureFlags\FeatureFlagRuleInput;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use App\Support\FeatureFlags\FeatureFlagScopeType;
use App\Support\FeatureFlags\FeatureFlagSubject;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * `RN-BO-99`, `datos.md §9.6`. Única implementación de
 * `FeatureFlagAdministration` — la única clase de todo el producto,
 * junto a `EloquentFeatureFlagEvaluator`, que toca `FeatureFlag`/
 * `FeatureFlagRule` (`CA-BO-169`).
 *
 * No abre transacción propia: el llamador (`REQ-BO`) ya corre dentro de
 * `DB::connection('pgsql_platform')->transaction()` cuando necesita
 * atomicidad con la escritura de `admin_action_logs` — mismo patrón que
 * `ModuleContractingService::apply()`.
 */
final class EloquentFeatureFlagAdministration implements FeatureFlagAdministration
{
    private const TENANT_IMPACT_THRESHOLD = 50;

    public function __construct(
        private readonly FeatureFlagCatalogCache $catalogCache,
    ) {}

    public function paginate(
        ?string $moduleCode,
        ?string $status,
        ?bool $retired,
        ?string $q,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = FeatureFlag::query()->withCount('rules');

        if ($moduleCode !== null) {
            $query->where('module_code', $moduleCode);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($retired !== null) {
            $retired ? $query->whereNotNull('retired_at') : $query->whereNull('retired_at');
        }

        if ($q !== null && $q !== '') {
            $query->where('key', 'ilike', '%'.$q.'%');
        }

        $paginator = $query->orderBy('key')->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(fn (FeatureFlag $flag): array => $this->summarize($flag));

        /** @var LengthAwarePaginator<int, array<string, mixed>> $paginator */
        return $paginator;
    }

    public function find(string $key): ?array
    {
        $flag = $this->query()->where('key', $key)->first();

        if ($flag === null) {
            return null;
        }

        $flag->loadMissing(['rules' => fn ($query) => $query->orderBy('id')]);

        return [
            ...$this->summarize($flag),
            'rules' => $flag->rules->map(fn (FeatureFlagRule $rule): array => $this->serializeRule($rule))->all(),
        ];
    }

    public function previewRules(string $key, FeatureFlagRuleSet $proposed): array
    {
        $flag = $this->findFlagOrFail($key);
        $this->guardNotRetired($flag);

        return $this->computeImpact($this->currentRuleSet($flag), $proposed);
    }

    public function setState(string $key, string $status, string $reason, ?int $actorId): array
    {
        $flag = $this->findFlagOrFail($key);
        $this->guardNotRetired($flag);

        // datos.md §9.7: SIEMPRE `SET rules_version = rules_version + 1`,
        // nunca leer-y-escribir en PHP (RN-BO-42) — increment() con
        // atributos adicionales hace las dos cosas en una sola sentencia
        // `UPDATE`, atómica.
        $flag->increment('rules_version', 1, [
            'status' => $status,
            'status_reason' => $reason,
        ]);

        $this->catalogCache->forget();

        return [
            'public_id' => $flag->public_id,
            'key' => $flag->key,
            'status' => $flag->status,
            'status_reason' => $flag->status_reason,
            'rules_version' => (int) $flag->rules_version,
        ];
    }

    public function replaceRules(string $key, FeatureFlagRuleSet $proposed, string $reason, ?int $actorId): array
    {
        $flag = $this->findFlagOrFail($key);
        $this->guardNotRetired($flag);

        $before = $this->currentRuleSet($flag);

        $existing = FeatureFlagRule::query()->where('feature_flag_id', $flag->id)->get();
        $existingByAxis = $existing->keyBy(fn (FeatureFlagRule $rule): string => $this->axisKey($rule->scope_type->value, $rule->affected_tenant_id, $rule->role_code));

        $tenantIdsByPublicId = $this->resolveTenantIds($proposed->rules);

        $seenAxisKeys = [];

        foreach ($proposed->rules as $ruleInput) {
            // `$ruleInput->tenantId` ya viene resuelto por quien construyó
            // el conjunto propuesto (Backoffice, desde `tenant_public_id`
            // del cuerpo de la petición); `$tenantIdsByPublicId` es sólo
            // una resolución defensiva de respaldo.
            $affectedTenantId = $ruleInput->scopeType === FeatureFlagScopeType::Tenant
                ? ($ruleInput->tenantId ?? $tenantIdsByPublicId[$ruleInput->tenantPublicId] ?? null)
                : null;

            if ($ruleInput->scopeType === FeatureFlagScopeType::Tenant && $affectedTenantId === null) {
                throw ApiException::validation([
                    'rules' => [[
                        'code' => 'bo.flag.invalid_rule',
                        'message' => __('bo.flag.invalid_rule'),
                        'params' => ['tenant_public_id' => $ruleInput->tenantPublicId],
                    ]],
                ]);
            }

            $axisKey = $this->axisKey($ruleInput->scopeType->value, $affectedTenantId, $ruleInput->roleCode);

            if (isset($seenAxisKeys[$axisKey])) {
                throw ApiException::validation([
                    'rules' => [[
                        'code' => 'bo.flag.duplicate_rule',
                        'message' => __('bo.flag.duplicate_rule'),
                        'params' => [],
                    ]],
                ]);
            }

            $seenAxisKeys[$axisKey] = true;

            /** @var FeatureFlagRule|null $current */
            $current = $existingByAxis->get($axisKey);

            if ($current !== null) {
                $current->fill([
                    'enabled' => $ruleInput->enabled,
                    'percentage' => $ruleInput->percentage,
                    'reason' => $reason,
                    'updated_by' => $actorId,
                ])->save();
            } else {
                FeatureFlagRule::query()->create([
                    'feature_flag_id' => $flag->id,
                    'scope_type' => $ruleInput->scopeType,
                    'affected_tenant_id' => $affectedTenantId,
                    'role_code' => $ruleInput->roleCode,
                    'percentage' => $ruleInput->percentage,
                    'enabled' => $ruleInput->enabled,
                    'reason' => $reason,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
        }

        // Lo que existía y ya no está en el conjunto propuesto: borrado
        // lógico. Conserva su fila con `deleted_at` — prueba de a qué
        // centros se expuso qué y cuándo (RN-BO-104, datos.md §13).
        foreach ($existingByAxis as $axisKey => $rule) {
            if (! isset($seenAxisKeys[$axisKey])) {
                // `deleted_at` no está en $fillable (a propósito: nadie
                // debe poder escribirlo por asignación masiva desde una
                // petición) — `update(['deleted_at' => ...])` lo
                // ignoraría en silencio. `delete()` es el camino de
                // `SoftDeletes`, correcto y explícito.
                $rule->updated_by = $actorId;
                $rule->save();
                $rule->delete();
            }
        }

        // RN-BO-104: UN solo incremento por petición completa, no uno por
        // regla tocada.
        $flag->increment('rules_version');

        $this->catalogCache->forget();

        $flag->refresh();
        $currentRules = FeatureFlagRule::query()->where('feature_flag_id', $flag->id)->orderBy('id')->get();

        return [
            'public_id' => $flag->public_id,
            'key' => $flag->key,
            'rules_version' => (int) $flag->rules_version,
            'rules' => $currentRules->map(fn (FeatureFlagRule $rule): array => $this->serializeRule($rule))->all(),
            'impact' => $this->computeImpact($before, $proposed),
            'affected_tenant_id' => $this->soleTenantAffectedBy($proposed),
        ];
    }

    /**
     * @param  list<FeatureFlagRuleInput>  $ruleInputs
     * @return array<string, int>
     */
    private function resolveTenantIds(array $ruleInputs): array
    {
        $publicIds = array_values(array_unique(array_filter(array_map(
            static fn (FeatureFlagRuleInput $rule): ?string => $rule->scopeType === FeatureFlagScopeType::Tenant ? $rule->tenantPublicId : null,
            $ruleInputs,
        ))));

        if ($publicIds === []) {
            return [];
        }

        return Tenant::withTrashed()->whereIn('public_id', $publicIds)->pluck('id', 'public_id')->all();
    }

    private function axisKey(string $scopeType, ?int $affectedTenantId, ?string $roleCode): string
    {
        return match ($scopeType) {
            'tenant' => "tenant:{$affectedTenantId}",
            'role' => "role:{$roleCode}",
            default => $scopeType,
        };
    }

    private function currentRuleSet(FeatureFlag $flag): FeatureFlagRuleSet
    {
        $rules = FeatureFlagRule::query()->where('feature_flag_id', $flag->id)->get();

        return new FeatureFlagRuleSet(
            flagKey: $flag->key,
            rolloutUnit: FeatureFlagRolloutUnit::from($flag->rollout_unit),
            // Sin `tenantPublicId` aquí a propósito: el motor de decisión
            // compara `tenantId` (entero interno), nunca el ULID — cargarlo
            // por regla obligaría a una consulta a `tenants` por cada
            // regla nominal del *flag*, evitable en este camino (que
            // además no es el de evaluación en caliente, pero tampoco
            // hace falta pagarlo). `serializeRule()` sí lo resuelve, para
            // la respuesta administrativa.
            rules: $rules->map(fn (FeatureFlagRule $rule): FeatureFlagRuleInput => new FeatureFlagRuleInput(
                scopeType: $rule->scope_type,
                enabled: $rule->enabled,
                tenantId: $rule->affected_tenant_id,
                roleCode: $rule->role_code,
                percentage: $rule->percentage,
                publicId: $rule->public_id,
            ))->all(),
            moduleCode: $flag->module_code,
        );
    }

    /**
     * `api.md §2.13`, `§2.11.1`: mismo cálculo para la vista previa y
     * para el campo `impact` de una escritura real. Itera el parque
     * completo (decenas/cientos de centros): operación de plataforma,
     * infrecuente, nunca en el camino de petición de un centro
     * (`RN-BO-106` no le aplica — no es el evaluador).
     *
     * @return array<string, mixed>
     */
    private function computeImpact(FeatureFlagRuleSet $before, FeatureFlagRuleSet $after): array
    {
        $tenants = Tenant::query()->select(['id', 'public_id', 'early_adopter_since'])->get();

        $beforeExposed = [];
        $afterExposed = [];
        $byRule = ['global' => 0, 'tenant' => 0, 'early_adopters' => 0, 'percentage' => 0];

        foreach ($tenants as $tenant) {
            $subject = new FeatureFlagSubject(
                tenantId: $tenant->id,
                tenantPublicId: $tenant->public_id,
                isEarlyAdopter: $tenant->early_adopter_since !== null,
            );

            if (FeatureFlagDecisionEngine::decide($before, $subject, retired: false, forcedOff: false)->enabled) {
                $beforeExposed[$tenant->public_id] = true;
            }

            $afterDecision = FeatureFlagDecisionEngine::decide($after, $subject, retired: false, forcedOff: false);

            if ($afterDecision->enabled) {
                $afterExposed[$tenant->public_id] = true;
                $matchKey = $afterDecision->matchedBy->value;

                if (isset($byRule[$matchKey])) {
                    $byRule[$matchKey]++;
                }
            }
        }

        $newlyExposed = array_values(array_diff(array_keys($afterExposed), array_keys($beforeExposed)));
        $newlyHidden = array_values(array_diff(array_keys($beforeExposed), array_keys($afterExposed)));

        $roleFilter = array_values(array_unique(array_filter(array_map(
            static fn (FeatureFlagRuleInput $rule): ?string => ($rule->scopeType === FeatureFlagScopeType::Role && $rule->enabled) ? $rule->roleCode : null,
            $after->rules,
        ))));

        return [
            'rollout_unit' => $after->rolloutUnit->value,
            'exposed_tenants' => count($afterExposed),
            'total_tenants' => $tenants->count(),
            // api.md §2.13: "por encima de un umbral se devuelve sólo el
            // recuento" — el recuento ya viaja en exposed_tenants.
            'newly_exposed' => count($newlyExposed) > self::TENANT_IMPACT_THRESHOLD ? [] : $newlyExposed,
            'newly_hidden' => count($newlyHidden) > self::TENANT_IMPACT_THRESHOLD ? [] : $newlyHidden,
            'by_rule' => $byRule,
            'role_filter' => $roleFilter,
        ];
    }

    private function soleTenantAffectedBy(FeatureFlagRuleSet $proposed): ?int
    {
        if (count($proposed->rules) !== 1) {
            return null;
        }

        $only = $proposed->rules[0];

        if ($only->scopeType !== FeatureFlagScopeType::Tenant) {
            return null;
        }

        return $only->tenantId;
    }

    private function findFlagOrFail(string $key): FeatureFlag
    {
        $flag = $this->query()->where('key', $key)->first();

        if ($flag === null) {
            throw ApiException::notFound();
        }

        return $flag;
    }

    private function guardNotRetired(FeatureFlag $flag): void
    {
        if ($flag->retired_at !== null) {
            throw ApiException::validation([
                'key' => [[
                    'code' => 'bo.flag.retired',
                    'message' => __('bo.flag.retired'),
                    'params' => [],
                ]],
            ]);
        }
    }

    /**
     * @return Builder<FeatureFlag>
     */
    private function query(): Builder
    {
        return FeatureFlag::query();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(FeatureFlag $flag): array
    {
        return [
            'key' => $flag->key,
            'module_code' => $flag->module_code,
            'name_key' => $flag->name_key,
            'description_key' => $flag->description_key,
            'rollout_unit' => $flag->rollout_unit,
            'status' => $flag->status,
            'status_reason' => $flag->status_reason,
            'rules_version' => (int) $flag->rules_version,
            'retired_at' => $flag->retired_at?->toJSON(),
            'rules_count' => (int) ($flag->rules_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRule(FeatureFlagRule $rule): array
    {
        return [
            'public_id' => $rule->public_id,
            'scope_type' => $rule->scope_type->value,
            'tenant_public_id' => $rule->affected_tenant_id !== null ? $rule->tenant?->public_id : null,
            'role_code' => $rule->role_code,
            'percentage' => $rule->percentage,
            'enabled' => $rule->enabled,
            'reason' => $rule->reason,
        ];
    }
}
