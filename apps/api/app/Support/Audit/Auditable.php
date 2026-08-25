<?php

namespace App\Support\Audit;

/**
 * ADR-035 §2: contrato obligatorio de todo modelo con registro automático
 * en `audit_logs`. auditValuePolicy() no tiene implementación por defecto
 * a propósito — un modelo que no la declara no compila su registro en el
 * *morph map* (ver test de arquitectura en CoreModelsTest). Las otras tres
 * las cubre HasAuditableAttributes con arrays vacíos por defecto.
 */
interface Auditable
{
    public function auditValuePolicy(): AuditValuePolicy;

    /**
     * Lista de inclusión (ADR-035 §2): solo tiene efecto con política Selective.
     *
     * @return array<int, string>
     */
    public function auditRecordedAttributes(): array;

    /**
     * Secretos propios del modelo, además del patrón global de config('audit.secret_attribute_patterns').
     *
     * @return array<int, string>
     */
    public function auditSecretAttributes(): array;

    /**
     * ADR-040 §4.1: eventos automáticos que este modelo NO registra en
     * `audit_logs`, porque el hecho ya queda registrado por otro camino
     * con su propio `event` (ADR-040 §4.3). Excepción, no configuración:
     * la carga de la prueba de que procede está en ADR-040 §4.5. `[]` en
     * el modelo que no excluye nada — HasAuditableAttributes lo cubre por
     * defecto sin que ningún modelo existente cambie una línea.
     *
     * @return array<int, string>
     */
    public function auditExcludedEvents(): array;
}
