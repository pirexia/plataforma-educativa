<?php

namespace App\Support\FeatureFlags;

/**
 * funcional.md §5.11.8.2. La interfaz que el backoffice necesita y que
 * `FeatureFlagEvaluator` no puede servir por construcción: evaluar para
 * **otro** centro, sin tenant activo (`ADR-046 §6.4`), y devolviendo el
 * motivo (`matchedBy`) que el producto no debe entregar.
 *
 * Dos interfaces y no dos métodos de una: `FeatureFlagExplainer` acepta
 * un sujeto arbitrario, así que su enlace por defecto tiene que poder
 * denegar los propósitos de backoffice (mismo patrón que
 * `PlatformAccessCheck`, `ADR-046 §6.3`) sin que eso afecte a
 * `FeatureFlagEvaluator`. Un test de arquitectura comprueba que ningún
 * fichero fuera de `app/Modules/Backoffice` la inyecta (`CA-BO-169`).
 *
 * Las tres operaciones delegan en la **misma** función de decisión pura
 * que sirve a `FeatureFlagEvaluator::isEnabled()` (`RN-BO-101`): un solo
 * algoritmo, para que el `matched_by` que un operador lee al depurar sea
 * siempre el motivo real.
 */
interface FeatureFlagExplainer
{
    public function explain(FeatureFlagSubject $subject, string $flagKey): FeatureFlagDecision;

    /**
     * @return array<string, FeatureFlagDecision> indexado por clave de flag
     */
    public function explainAll(FeatureFlagSubject $subject): array;

    /**
     * Evalúa un conjunto de reglas **propuesto**, no el escrito: es lo
     * que hace honesta la vista previa de `POST .../rules/preview`
     * (`RN-BO-68`) sin tocar la base de datos ni bloquear nada.
     */
    public function explainWith(FeatureFlagRuleSet $proposed, FeatureFlagSubject $subject): FeatureFlagDecision;
}
