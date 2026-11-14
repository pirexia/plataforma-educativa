<?php

namespace App\Modules\Core\Domain;

use App\Support\FeatureFlags\FeatureFlagDecision;
use App\Support\FeatureFlags\FeatureFlagMatchedBy;
use App\Support\FeatureFlags\FeatureFlagRolloutUnit;
use App\Support\FeatureFlags\FeatureFlagRuleInput;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use App\Support\FeatureFlags\FeatureFlagScopeType;
use App\Support\FeatureFlags\FeatureFlagSubject;

/**
 * funcional.md §5.11.5, `RN-BO-37`, `RN-BO-101`. **Un solo algoritmo, en
 * una función de decisión pura**: sin acceso a base de datos dentro, sin
 * caché, sin efectos — mismo argumento que `RN-BO-22` para el cierre de
 * dependencias de módulos. `EloquentFeatureFlagEvaluator` delega aquí
 * tanto para `FeatureFlagEvaluator::isEnabled()` como para las tres
 * operaciones de `FeatureFlagExplainer`, así que el `matched_by` que un
 * operador lee al depurar es siempre el motivo real (`CA-BO-170`).
 *
 * El reparto por porcentaje (`RN-BO-38`, `RN-BO-39`, funcional.md
 * §5.11.6) es `hash(clave_del_flag ⊕ public_id del sujeto) mod 100`:
 * determinista, sin almacenamiento y sin relleno retroactivo. La clave
 * entra en el hash para que dos *flags* al mismo porcentaje no expongan
 * al mismo conjunto.
 */
final class FeatureFlagDecisionEngine
{
    /**
     * @param  bool  $retired  `feature_flags.retired_at IS NOT NULL` (RN-BO-44).
     * @param  bool  $forcedOff  `feature_flags.status = 'forced_off'` (RN-BO-37, RNF-MANT-005).
     * @param  ?bool  $moduleAvailable  `ModuleAvailability::isEnabled($flag->module_code)`, resuelto por el llamador (`RN-BO-102`, §5.11.8.4) — `null` cuando `module_code` es nulo (flags del núcleo, el paso se salta entero). Se recibe ya calculado y no aquí dentro: esta función sigue sin tocar base de datos ni caché (`RN-BO-101`).
     */
    public static function decide(
        FeatureFlagRuleSet $rules,
        FeatureFlagSubject $subject,
        bool $retired,
        bool $forcedOff,
        ?bool $moduleAvailable = null,
    ): FeatureFlagDecision {
        // RN-BO-102: paso 0, antes del catálogo y antes de forced_off.
        if ($moduleAvailable === false) {
            return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::ModuleDisabled);
        }

        if ($retired) {
            return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::Retired);
        }

        if ($forcedOff) {
            return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::ForcedOff);
        }

        $exposure = self::exposure($rules, $subject);

        if (! $exposure->enabled) {
            return $exposure;
        }

        if (! self::passesRoleFilter($rules, $subject)) {
            return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::RoleFiltered);
        }

        return $exposure;
    }

    /**
     * Paso 3 de §5.11.5: exposición del centro, parando en la primera
     * regla que aplique. El rol NO es un quinto paso aquí — es un
     * filtro posterior (§5.11.4): un centro no expuesto no pasa a
     * estarlo porque un usuario tenga un rol listado.
     */
    private static function exposure(FeatureFlagRuleSet $rules, FeatureFlagSubject $subject): FeatureFlagDecision
    {
        $tenantRule = self::find($rules, FeatureFlagScopeType::Tenant, static fn (FeatureFlagRuleInput $rule): bool => $rule->tenantId === $subject->tenantId);

        if ($tenantRule !== null) {
            return FeatureFlagDecision::of($tenantRule->enabled, FeatureFlagMatchedBy::Tenant);
        }

        $earlyAdoptersRule = self::find($rules, FeatureFlagScopeType::EarlyAdopters);

        if ($earlyAdoptersRule !== null && $earlyAdoptersRule->enabled && $subject->isEarlyAdopter) {
            return FeatureFlagDecision::of(true, FeatureFlagMatchedBy::EarlyAdopters);
        }

        $percentageRule = self::find($rules, FeatureFlagScopeType::Percentage);

        if ($percentageRule !== null && $percentageRule->enabled && $percentageRule->percentage !== null) {
            $subjectId = $rules->rolloutUnit === FeatureFlagRolloutUnit::User
                ? $subject->userPublicId
                : $subject->tenantPublicId;

            // Sin identificador para la unidad declarada (típicamente un
            // flag `rollout_unit: user` evaluado sin usuario concreto):
            // esta regla no se puede resolver de forma determinista, así
            // que no decide y la cadena continúa con la regla global.
            if ($subjectId !== null && self::bucket($rules->flagKey, $subjectId) < $percentageRule->percentage) {
                return FeatureFlagDecision::of(true, FeatureFlagMatchedBy::Percentage);
            }
        }

        $globalRule = self::find($rules, FeatureFlagScopeType::Global);

        if ($globalRule !== null && $globalRule->enabled) {
            return FeatureFlagDecision::of(true, FeatureFlagMatchedBy::Global);
        }

        return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::None);
    }

    /**
     * `RN-BO-40`, `RN-BO-41`. Si el *flag* no tiene ninguna regla de rol
     * activa, el filtro no existe y pasa trivialmente. Si tiene alguna,
     * sólo pasa un sujeto con usuario cuyos códigos de rol intersequen el
     * conjunto — sin usuario, siempre falso.
     */
    private static function passesRoleFilter(FeatureFlagRuleSet $rules, FeatureFlagSubject $subject): bool
    {
        $roleCodes = array_values(array_filter(
            array_map(
                static fn (FeatureFlagRuleInput $rule): ?string => $rule->enabled ? $rule->roleCode : null,
                array_filter($rules->rules, static fn (FeatureFlagRuleInput $rule): bool => $rule->scopeType === FeatureFlagScopeType::Role),
            ),
        ));

        if ($roleCodes === []) {
            return true;
        }

        if ($subject->userPublicId === null) {
            return false;
        }

        return array_intersect($roleCodes, $subject->roleCodes) !== [];
    }

    private static function find(FeatureFlagRuleSet $rules, FeatureFlagScopeType $scopeType, ?callable $matches = null): ?FeatureFlagRuleInput
    {
        foreach ($rules->rules as $rule) {
            if ($rule->scopeType !== $scopeType) {
                continue;
            }

            if ($matches === null || $matches($rule)) {
                return $rule;
            }
        }

        return null;
    }

    private static function bucket(string $flagKey, string $subjectPublicId): int
    {
        $digest = hash('sha256', "{$flagKey}\0{$subjectPublicId}", true);
        $value = unpack('N', substr($digest, 0, 4));

        return $value[1] % 100;
    }
}
